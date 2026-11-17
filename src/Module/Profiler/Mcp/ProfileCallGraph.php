<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Mcp;

use Buggregator\Trap\Module\Mcp\Tool;
use Buggregator\Trap\Module\Mcp\Tool\Arguments;
use Buggregator\Trap\Module\Mcp\Tool\MaxLength;
use Buggregator\Trap\Module\Profiler\Struct\Branch;
use Buggregator\Trap\Module\Profiler\Struct\Edge;
use Buggregator\Trap\Module\Profiler\Struct\Metric;

/**
 * The call tree of a profile as text, pruned to the calls above a share of the metric.
 *
 * @internal
 */
final class ProfileCallGraph implements Tool
{
    private const THRESHOLD = 1.0;
    private const MIN_THRESHOLD = 0.01;
    private const MAX_DEPTH = 30;
    private const MAX_MAX_DEPTH = 200;
    private const MAX_LENGTH = 20_000;
    private const MAX_MAX_LENGTH = 200_000;

    public function __construct(
        private readonly ProfileAnalyses $analyses,
    ) {}

    #[\Override]
    public function name(): string
    {
        return 'profile_call_graph';
    }

    #[\Override]
    public function title(): string
    {
        return 'Call tree of a profile';
    }

    #[\Override]
    public function description(): string
    {
        return 'Shows the call tree of a profile as text, from the root down: each call with its share and value of '
            . 'the metric and its count, the heaviest first, only the calls of at least `threshold` percent; '
            . 'the smaller ones of a caller are summed in one line. For the paths through one function, '
            . '`profile_calls` is cheaper.';
    }

    #[\Override]
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ProfileAnalyses::idSchema(),
                'metric' => ProfileSchema::metric(),
                'threshold' => [
                    'type' => 'number',
                    'minimum' => self::MIN_THRESHOLD,
                    'maximum' => 100,
                    'default' => self::THRESHOLD,
                    'description' => 'The smallest share of the metric to show a call of, %.',
                ],
                'max_depth' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_MAX_DEPTH,
                    'default' => self::MAX_DEPTH,
                    'description' => 'The deepest level to show; the root is 1.',
                ],
                'max_length' => MaxLength::schema(self::MAX_LENGTH, maximum: self::MAX_MAX_LENGTH),
            ],
            'required' => ['id'],
            'additionalProperties' => false,
        ];
    }

    #[\Override]
    public function outputSchema(): ?array
    {
        return null;
    }

    #[\Override]
    public function annotations(): array
    {
        return ['readOnlyHint' => true, 'openWorldHint' => false];
    }

    #[\Override]
    public function call(array $arguments): string
    {
        $analysis = $this->analyses->get($arguments);
        $metric = $analysis->metric($arguments);
        $threshold = Arguments::number($arguments, 'threshold', self::MIN_THRESHOLD) ?? self::THRESHOLD;
        $threshold <= 100.0 or throw new \InvalidArgumentException('`threshold` must be a number from 0.01 to 100.');
        $maxDepth = Arguments::integer($arguments, 'max_depth', 1, self::MAX_MAX_DEPTH) ?? self::MAX_DEPTH;
        $maxLength = MaxLength::fromArguments($arguments, self::MAX_LENGTH, self::MAX_MAX_LENGTH);

        $profile = $analysis->profile;
        $tree = $analysis->tree();
        $peak = $analysis->peak($metric);
        $lines = [\sprintf(
            '%s profile %s: the call tree by %s, the calls of %s%% or more; %s%s in parentheses.%s%s',
            $analysis->payload->type->value,
            $analysis->id,
            match ($metric) {
                Metric::WallTime => 'wall time',
                Metric::CpuTime => 'CPU time',
                Metric::Memory => 'memory',
                Metric::PeakMemory => 'peak memory',
                Metric::Count => $analysis->countType,
            },
            self::number($threshold),
            $metric === Metric::Count ? '' : \sprintf('100%% is %s, ', $metric->format($peak)),
            $analysis->countType,
            $profile->exactTree ? '' : ' The profiler sums the calls by caller and callee, so the tree is unfolded '
                . 'from them: a function called from several places has its callees split by their shares.',
            $tree->cut ? ' The tree unfolds into too many calls, so it is cut: it keeps the calls in the order they '
                . 'were made up to a limit and may leave out later ones, heavy ones too. The hot path of '
                . '`profile_summary` and `profile_calls` show the heaviest paths.' : '',
        )];

        $minValue = (float) $peak * $threshold / 100.0;
        $length = \mb_strlen($lines[0]) + 1;
        // Iterative: the depth follows the recursion in the profiled code. A string is a line to add after a subtree.
        /** @var list<array{list<Branch<Edge>>, int<1, max>}|string> $stack */
        $stack = [[\array_values($tree->root), 1]];
        $slice = $analysis->timeSlice();
        // Past `max_length` the rest would be cut anyway, so the length of the whole text stays unknown
        while ($stack !== [] && $length <= $maxLength) {
            $slice->tick();
            $entry = \array_pop($stack);
            if (\is_string($entry)) {
                $lines[] = $entry;
                $length += \mb_strlen($entry) + 1;
                continue;
            }

            [$branches, $depth] = $entry;
            $indent = \str_repeat('  ', $depth - 1);
            \usort($branches, static fn(Branch $a, Branch $b): int => $metric->of($b->item->cost) <=> $metric->of($a->item->cost));

            $shown = [];
            $hidden = 0;
            $hiddenValue = 0;
            foreach ($branches as $branch) {
                $value = $metric->of($branch->item->cost);
                if ($value > 0 && (float) $value >= $minValue) {
                    $shown[] = $branch;
                } else {
                    ++$hidden;
                    $hiddenValue += $value;
                }
            }

            $hidden === 0 or $stack[] = \sprintf(
                '%s  %s  %s… %d more %s below %s%%',
                self::padLeft(self::percent($analysis->percent($metric, $hiddenValue)), 6),
                self::padLeft($metric->format($hiddenValue), 11),
                $indent,
                $hidden,
                $hidden === 1 ? 'call' : 'calls',
                self::number($threshold),
            );
            foreach (\array_reverse($shown) as $branch) {
                $cost = $branch->item->cost;
                $value = $metric->of($cost);
                $children = $branch->children;
                if ($children !== [] && $depth === $maxDepth) {
                    $stack[] = \sprintf('%21s%s  … deeper calls left out: `max_depth`', '', $indent);
                } elseif ($children !== []) {
                    $stack[] = [$children, $depth + 1];
                }
                $stack[] = \sprintf(
                    '%s  %s  %s%s%s',
                    self::padLeft(self::percent($analysis->percent($metric, $value)), 6),
                    self::padLeft($metric->format($value), 11),
                    $indent,
                    $branch->item->callee,
                    // A root added for a profile without `main()` has no calls of its own
                    $branch->item->callerId === null && $cost->ct === 0 ? '' : " ($cost->ct)",
                );
            }
        }

        return MaxLength::cut(\implode("\n", $lines) . "\n", $maxLength, $length, more: $stack !== []);
    }

    private static function percent(?float $percent): string
    {
        return $percent === null ? '' : Metric::formatPercent($percent);
    }

    private static function number(float $value): string
    {
        return \rtrim(\rtrim(\sprintf('%.2f', $value), '0'), '.');
    }

    private static function padLeft(string $value, int $width): string
    {
        return \str_repeat(' ', \max(0, $width - \mb_strlen($value))) . $value;
    }
}
