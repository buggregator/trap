<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Mcp;

use Buggregator\Trap\Module\Mcp\Tool\Arguments;
use Buggregator\Trap\Module\Mcp\Tool\Wildcard;
use Buggregator\Trap\Module\Profiler\Frame\Payload;
use Buggregator\Trap\Module\Profiler\Frontend\Mapper;
use Buggregator\Trap\Module\Profiler\Frontend\Message\TopFunctions\Func;
use Buggregator\Trap\Module\Profiler\Struct\Edge;
use Buggregator\Trap\Module\Profiler\Struct\Metric;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Module\Profiler\Struct\Tree;
use Buggregator\Trap\Support\TimeSlice;

/**
 * What the profile tools compute of one profile, kept for the next calls: summing the functions of a large profile
 * takes a few hundred milliseconds, and the computations let the other fibers run meanwhile.
 *
 * @psalm-type Row = array{
 *     function: string,
 *     calls: int,
 *     inclusive: int,
 *     inclusive_percent: float|null,
 *     exclusive: int,
 *     exclusive_percent: float|null
 * }
 *
 * @internal
 */
final class ProfileAnalysis
{
    /** Names compared to a misspelled one at most: a comparison takes the product of the lengths. */
    private const MAX_SIMILAR_CANDIDATES = 5_000;

    public readonly Profile $profile;

    /**
     * What `ct` counts: `calls`, `samples`, `sampled calls` or the sample type of a pprof profile.
     *
     * @var non-empty-string
     */
    public readonly string $countType;

    /** @var list<Metric> */
    public readonly array $metrics;

    /** @var array<string, Func>|null By names */
    private ?array $functions = null;

    /** @var array<non-empty-string, list<Func>> By metrics and orders */
    private array $ranked = [];

    /** @var array<string, list<non-empty-string>>|null Function IDs, one per recursion level, by names */
    private ?array $ids = null;

    /** @var array<non-empty-string, int<0, max>> By metric values */
    private array $peaks = [];

    /** @var array<non-empty-string, array{int, int<0, max>}> Own and inclusive values by `metric:functionId` keys */
    private array $own = [];

    /** @var array<non-empty-string, true> The computations a fiber has started and suspended in, by names */
    private array $computing = [];

    /** @var \Closure(): TimeSlice */
    private readonly \Closure $timeSlices;

    /**
     * @param (\Closure(): TimeSlice)|null $timeSlices Makes the slices of time the computations share the process by.
     * @param (\Closure(): int<1, max>)|null $nodeBudget The nodes of the call tree the memory left affords:
     *        see {@see Profile::tree()}.
     */
    public function __construct(
        public readonly string $id,
        public readonly Payload $payload,
        ?\Closure $timeSlices = null,
        private readonly ?\Closure $nodeBudget = null,
    ) {
        $this->profile = $payload->getProfile();
        $this->countType = Mapper::countType($payload) ?? 'calls';
        $this->metrics = Metric::measured($this->profile->peaks);
        $this->timeSlices = $timeSlices ?? static fn(): TimeSlice => new TimeSlice();
    }

    /**
     * A slice of time for a long computation to let the other fibers run by.
     */
    public function timeSlice(): TimeSlice
    {
        return ($this->timeSlices)();
    }

    /**
     * The `metric` argument, or the metric the console ranks by.
     *
     * @param array<array-key, mixed> $arguments
     *
     * @throws \InvalidArgumentException When the profile doesn't measure the metric, or measures nothing.
     */
    public function metric(array $arguments): Metric
    {
        if (($arguments['metric'] ?? null) === null) {
            return Metric::rank($this->profile->peaks)
                ?? throw new \InvalidArgumentException("Profile `$this->id` measures nothing: it has no calls.");
        }

        $metric = Metric::from(Arguments::choice(
            $arguments,
            'metric',
            \array_map(static fn(Metric $metric): string => $metric->value, Metric::cases()),
        ));
        \in_array($metric, $this->metrics, true) or throw new \InvalidArgumentException(\sprintf(
            '%s profile `%s` does not measure `%s`; it has: %s.',
            $this->payload->type->value,
            $this->id,
            $metric->value,
            $this->metrics === [] ? 'nothing' : \implode(', ', $this->metricNames()),
        ));

        return $metric;
    }

    /**
     * @return list<non-empty-string>
     */
    public function metricNames(): array
    {
        return \array_map(static fn(Metric $metric): string => $metric->value, $this->metrics);
    }

    /**
     * The value of the metric the percentages are of: the peak value of one caller and callee pair, as the console
     * shows, or the sum of the roots when larger, up to `PHP_INT_MAX`. A root added for a profile without `main()`
     * sums the times of its calls, so it may pass every pair. The roots of Xdebug fibers are left out: their cost
     * counts in the calls that started or resumed the fibers too.
     *
     * @return int<0, max>
     */
    public function peak(Metric $metric): int
    {
        if (isset($this->peaks[$metric->value])) {
            return $this->peaks[$metric->value];
        }

        $peak = $metric->of($this->profile->peaks->toCost());
        if ($metric !== Metric::Count) {
            $roots = 0;
            foreach ($this->profile->calls->roots() as $edge) {
                // Received data may pass the integer range: the sum would turn into a float
                $value = $metric->of($edge->cost);
                $roots = $value > \PHP_INT_MAX - $roots ? \PHP_INT_MAX : $roots + $value;
            }
            /** @var mixed $fibers */
            $fibers = $this->profile->metadata['fibers'][$metric->value] ?? 0;
            \is_int($fibers) && $fibers > 0 && $roots < \PHP_INT_MAX and $roots = \max(0, $roots - $fibers);
            $peak = \max($peak, $roots);
        }

        return $this->peaks[$metric->value] = $peak;
    }

    /**
     * @return non-empty-string
     */
    public function unit(Metric $metric): string
    {
        return match ($metric) {
            Metric::WallTime, Metric::CpuTime => 'microseconds',
            Metric::Memory, Metric::PeakMemory => 'bytes',
            Metric::Count => $this->countType,
        };
    }

    /**
     * @return array<string, Func> The functions summed over all their calls, by names.
     */
    public function functions(): array
    {
        $this->waitFor('functions');
        if ($this->functions === null) {
            $this->computing['functions'] = true;
            try {
                $this->functions = Func::aggregate($this->profile->calls, $this->profile->peaks, $this->timeSlice());
            } finally {
                unset($this->computing['functions']);
            }
        }

        return $this->functions;
    }

    /**
     * @return Tree<Edge> See {@see Profile::tree()}.
     */
    public function tree(): Tree
    {
        return $this->profile->tree($this->timeSlice(), $this->nodeBudget === null ? null : ($this->nodeBudget)());
    }

    /**
     * @return list<Func> Heaviest first, as {@see Func::rank()} orders them.
     */
    public function ranked(Metric $metric, bool $inclusive = false): array
    {
        $key = $metric->value . ($inclusive ? ':inclusive' : ':exclusive');
        if (isset($this->ranked[$key])) {
            return $this->ranked[$key];
        }

        // Not `Func::rank()`: it orders by the raw sums, while the rows show them clamped
        $functions = \array_values($this->functions());
        $own = $total = $names = [];
        foreach ($functions as $func) {
            [$total[], $own[]] = self::values($func, $metric);
            $names[] = $func->function;
        }
        $inclusive
            ? \array_multisort($total, \SORT_DESC, $own, \SORT_DESC, $names, \SORT_ASC, $functions)
            : \array_multisort($own, \SORT_DESC, $total, \SORT_DESC, $names, \SORT_ASC, $functions);

        return $this->ranked[$key] = $functions;
    }

    /**
     * @return Row
     */
    public function row(Func $func, Metric $metric): array
    {
        [$inclusive, $exclusive] = self::values($func, $metric);

        return [
            'function' => $func->function,
            'calls' => (int) $func->get('ct'),
            'inclusive' => $inclusive,
            'inclusive_percent' => $this->percent($metric, $inclusive),
            'exclusive' => $exclusive,
            'exclusive_percent' => $this->percent($metric, $exclusive),
        ];
    }

    /**
     * @param int<0, max> $value
     *
     * @return float|null From 0 to 100: a function sums the calls of all its callers, which may pass the peak with
     *         XHProf's signed `mu` or cycles in received data. Null for `ct`: its peak is the most calls of one
     *         caller and callee pair, so the share would pass 100 % for most functions.
     */
    public function percent(Metric $metric, int|float $value): ?float
    {
        if ($metric === Metric::Count) {
            return null;
        }

        $peak = $this->peak($metric);

        return $peak > 0 ? \round(\min(100.0, \max(0.0, (float) $value / (float) $peak * 100.0)), 3) : 0.0;
    }

    /**
     * @throws \InvalidArgumentException With the names close to the given one.
     */
    public function function(string $name): Func
    {
        $functions = $this->functions();
        if (isset($functions[$name])) {
            return $functions[$name];
        }

        $close = $this->closeNames($name);
        throw new \InvalidArgumentException(\sprintf(
            'Profile `%s` has no function `%s`%s',
            $this->id,
            $name,
            $close === []
                ? '. Call `profile_top` for the names.'
                : ". Close matches:\n" . \implode("\n", $close),
        ));
    }

    /**
     * Function IDs carry recursion levels: `foo`, `foo@1`.
     *
     * @return list<non-empty-string> IDs of the function, one per recursion level.
     */
    public function ids(string $name): array
    {
        $this->waitFor('ids');
        if ($this->ids === null) {
            $this->computing['ids'] = true;
            try {
                $graph = $this->profile->calls;
                $slice = $this->timeSlice();
                $ids = [];
                foreach ($graph->functions() as $id) {
                    $ids[$graph->callers($id)[0]->callee][] = $id;
                    $slice->tick();
                }
                $this->ids = $ids;
            } finally {
                unset($this->computing['ids']);
            }
        }

        return $this->ids[$name] ?? [];
    }

    /**
     * The exclusive value of a call's metric: the callee's own cost in these calls. When the callee is called from
     * several places, its own cost is split by their shares of its inclusive cost.
     *
     * @param TimeSlice|null $timeSlice Lets other fibers run while the callees of a function are summed.
     */
    public function exclusive(Edge $edge, Metric $metric, ?TimeSlice $timeSlice = null): float
    {
        $value = $metric->of($edge->cost);
        if ($metric === Metric::Count || $value === 0) {
            return (float) $value;
        }

        // Kept: a function with many callers would walk its callees once per caller
        $key = $metric->value . ':' . $edge->calleeId;
        if (!isset($this->own[$key])) {
            $graph = $this->profile->calls;
            $inclusive = $metric->of($graph->inclusiveCost($edge->calleeId));
            $own = $inclusive;
            foreach ($graph->callees($edge->calleeId) as $child) {
                $own -= $metric->of($child->cost);
                $timeSlice?->tick();
            }
            $this->own[$key] = [$own, $inclusive];
        }
        [$own, $inclusive] = $this->own[$key];

        // Freed memory and timer jitter make a negative own cost possible
        return $inclusive > 0 && $own > 0
            ? \min((float) $own * (float) $value / (float) $inclusive, (float) $value)
            : 0.0;
    }

    /**
     * The values of the function as the rows show them: XHProf's `mu` is signed, so its sums may be negative, and
     * the own memory of a function freeing what its callees allocated may pass the inclusive one.
     *
     * @return array{int<0, max>, int<0, max>} The inclusive and the exclusive value.
     */
    private static function values(Func $func, Metric $metric): array
    {
        $inclusive = \max(0, (int) $func->get($metric->value));

        return [$inclusive, \min(\max(0, (int) $func->get('excl_' . $metric->value)), $inclusive)];
    }

    /**
     * Suspends the fiber while another one computes the same: computed twice, it would take twice the time and memory.
     *
     * @param non-empty-string $name
     */
    private function waitFor(string $name): void
    {
        // Outside a fiber nothing can wait: the computation starts over
        while (isset($this->computing[$name]) && \Fiber::getCurrent() !== null) {
            \Fiber::suspend();
        }
    }

    /**
     * @return list<string> The names containing the given one in any case, heaviest first; else the most similar.
     */
    private function closeNames(string $name): array
    {
        $limit = 10;
        $metric = Metric::rank($this->profile->peaks) ?? Metric::Count;
        $pattern = Wildcard::compile(\str_contains($name, '*') ? $name : "*$name*");
        $close = [];
        foreach ($this->ranked($metric, inclusive: true) as $func) {
            if ($pattern->matches($func->function)) {
                $close[] = $func->function;
                if (\count($close) === $limit) {
                    break;
                }
            }
        }

        if ($close !== [] || \str_contains($name, '*')) {
            return $close;
        }

        $needle = \strtolower($name);
        $maxDistance = \max(3, \intdiv(\strlen($name), 3));
        $distances = [];
        foreach ($this->ranked($metric, inclusive: true) as $func) {
            // Names differing in length by more can't be close; the heaviest are kept of too many candidates
            if (\abs(\strlen($func->function) - \strlen($name)) > $maxDistance) {
                continue;
            }
            $distances[$func->function] = \levenshtein($needle, \strtolower($func->function));
            if (\count($distances) === self::MAX_SIMILAR_CANDIDATES) {
                break;
            }
        }
        \asort($distances);

        return \array_map(
            'strval',
            \array_keys(\array_slice(\array_filter($distances, static fn(int $d): bool => $d <= $maxDistance), 0, 5, true)),
        );
    }
}
