<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Frontend;

use Buggregator\Trap\Module\Frontend\Event;
use Buggregator\Trap\Module\Frontend\EventMapper;
use Buggregator\Trap\Module\Profiler\Frame as ProfilerFrame;
use Buggregator\Trap\Module\Profiler\Frame\Payload as ProfilerPayload;
use Buggregator\Trap\Module\Profiler\Frame\Type as ProfilerPayloadType;
use Buggregator\Trap\Module\Profiler\Frontend\Message\CallGraph;
use Buggregator\Trap\Module\Profiler\Frontend\Message\FlameChart;
use Buggregator\Trap\Module\Profiler\Frontend\Message\TopFunctions;
use Buggregator\Trap\Module\Profiler\Struct\Branch;
use Buggregator\Trap\Module\Profiler\Struct\Cost;
use Buggregator\Trap\Module\Profiler\Struct\Edge;
use Buggregator\Trap\Module\Profiler\Struct\Graph;
use Buggregator\Trap\Module\Profiler\Struct\Peaks;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Server\Proto\Frame;

/**
 * @internal
 */
final class Mapper implements EventMapper
{
    /**
     * @return Event<ProfilerPayload>
     */
    #[\Override]
    public function map(Frame $frame): Event
    {
        \assert($frame instanceof ProfilerFrame);

        return new Event(
            uuid: $frame->payload->getProfile()->uuid,
            type: 'profiler',
            payload: $frame->payload,
            timestamp: (float) $frame->time->format('U.u'),
        );
    }

    /**
     * @param Event<ProfilerPayload> $event
     * @param string $metric Sorting key: `wt`, `excl_wt`, `ct`, etc.
     */
    public function topFunctions(Event $event, string $metric): TopFunctions
    {
        $profile = $event->payload->getProfile();
        $functions = $this->aggregateFunctions($profile->calls, $profile->peaks);

        $order = [];
        foreach ($functions as $name => $func) {
            $order[$name] = $func->get($metric);
        }
        \arsort($order);

        $sorted = [];
        foreach (\array_keys($order) as $name) {
            $sorted[] = $functions[$name];
        }

        return new TopFunctions(
            functions: $sorted,
            overallTotals: $profile->peaks,
            schema: new TopFunctions\Schema([
                new TopFunctions\Column(
                    key: 'function',
                    label: 'Function',
                    sortable: false,
                    description: 'Function that was called',
                    values: [new TopFunctions\Value(key: 'function', format: 'string')],
                ),
                new TopFunctions\Column(
                    key: 'ct',
                    label: 'CT',
                    sortable: true,
                    description: self::countsSamples($event->payload) ? 'Samples with the function on the stack' : 'Calls',
                    values: [new TopFunctions\Value(key: 'ct', format: 'number')],
                ),
                ...self::metricColumns($profile->peaks, 'cpu', 'CPU', 'CPU time (ms)', 'ms'),
                ...self::metricColumns($profile->peaks, 'wt', 'WT', 'Wall time (ms)', 'ms'),
                ...self::metricColumns($profile->peaks, 'mu', 'MU', 'Memory usage (bytes)', 'bytes'),
                ...self::metricColumns($profile->peaks, 'pmu', 'PMU', 'Peak memory usage (bytes)', 'bytes'),
            ]),
        );
    }

    /**
     * @param Event<ProfilerPayload> $event
     * @param float $threshold Functions and calls with a share of the metric not above it are hidden (%).
     *        A function keeps its heaviest incoming call and a call from the roots, so it stays linked to the graph.
     * @param float $percentage Functions with a share of the metric from it are colored (%).
     */
    public function callGraph(
        Event $event,
        float $threshold = 1,
        float $percentage = 15,
        string $metric = 'wt',
    ): CallGraph {
        $profile = $event->payload->getProfile();
        $graph = $profile->calls;
        $samples = self::countsSamples($event->payload);
        $nodes = [];
        $edges = [];
        /** @var array<non-empty-string, array{non-empty-string, string}> $visible Node IDs and colors by function IDs */
        $visible = [];

        foreach ($graph->functions() as $functionId) {
            $cost = self::withPercents($graph->inclusiveCost($functionId), $profile->peaks);
            $percent = self::percentOf($cost, $metric);
            $important = $percent >= $percentage;
            if (!$important && $percent <= $threshold) {
                continue;
            }

            $id = 'n' . (\count($visible) + 1);
            $color = $important ? Palette::callGraphNode($percent) : '#FFFFFF';
            $visible[$functionId] = [$id, $color];
            $name = $graph->callers($functionId)[0]->callee;

            $nodes[] = new CallGraph\Node(
                id: $id,
                name: match (true) {
                    $cost->ct === 0 => $name,
                    $samples => \sprintf('%s (%d %s)', $name, $cost->ct, $cost->ct === 1 ? 'sample' : 'samples'),
                    default => \sprintf('%s (%dx)', $name, $cost->ct),
                },
                cost: $cost,
                metrics: new CallGraph\Metrics(cost: self::costOf($cost, $metric), percents: $percent),
                color: $color,
                textColor: Palette::textOn($color),
            );
        }

        $calls = self::visibleCalls($profile, $visible);
        $percents = \array_map(
            static fn(array $call): float => self::percentOf(self::withPercents($call[2], $profile->peaks), $metric),
            $calls,
        );
        /** @var array<non-empty-string, int> $heaviest Index of the heaviest call by callee IDs */
        $heaviest = [];
        foreach ($calls as $i => [, $calleeId]) {
            isset($heaviest[$calleeId]) && $percents[$i] <= $percents[$heaviest[$calleeId]]
                or $heaviest[$calleeId] = $i;
        }
        $kept = [];
        foreach ($calls as $i => [, $calleeId]) {
            $percents[$i] > $threshold || $heaviest[$calleeId] === $i and $kept[$i] = true;
        }
        $kept = self::linkToRoots($calls, $percents, $kept);

        foreach ($calls as $i => [$callerId, $calleeId]) {
            if (!isset($kept[$i])) {
                continue;
            }

            $edges[] = new CallGraph\Edge(
                color: $visible[$calleeId][1],
                label: (string) $percents[$i] . '%',
                source: $visible[$callerId][0],
                target: $visible[$calleeId][0],
            );
        }

        return new CallGraph(
            toolbar: new CallGraph\Toolbar(\array_values(\array_filter(
                [
                    new CallGraph\Button('CPU', 'cpu', 'CPU time (ms)'),
                    new CallGraph\Button('Wall time', 'wt', 'Wall time (ms)'),
                    new CallGraph\Button('Memory', 'mu', 'Memory usage (bytes)'),
                    new CallGraph\Button('Peak memory', 'pmu', 'Peak memory usage (bytes)'),
                ],
                static fn(CallGraph\Button $button): bool => self::isMeasured($profile->peaks, $button->metric),
            ))),
            nodes: $nodes,
            edges: $edges,
        );
    }

    /**
     * @param Event<ProfilerPayload> $event
     */
    public function flameChart(Event $event): FlameChart
    {
        $roots = [];

        // Iterative: the depth follows the recursion in the profiled code, deeper than Xdebug's nesting limit
        /** @var list<array{Branch<Edge>, float, FlameChart\Span|null}> $stack Node, its start and parent span */
        $stack = self::layOut(\array_values($event->payload->getProfile()->tree()->root), 0.0, null);
        while ($stack !== []) {
            [$branch, $start, $parent] = \array_pop($stack);
            $cost = $branch->item->cost;
            $span = new FlameChart\Span(
                name: $branch->item->callee,
                start: $start,
                duration: self::duration($cost),
                cost: $cost,
                color: Palette::flameChartSpan($cost->p_wt),
            );
            $parent === null ? $roots[] = $span : $parent->children[] = $span;
            \array_push($stack, ...self::layOut($branch->children, $start, $span));
        }

        return new FlameChart($roots);
    }

    /**
     * Keeps the heaviest calls that link the functions unreachable from the roots, one at a time.
     *
     * The heaviest incoming calls alone may link functions only to each other: a call is not always
     * cheaper than its caller, e.g. when the caller frees the memory the callee allocated.
     *
     * @param list<array{non-empty-string, non-empty-string, Cost}> $calls Caller ID, callee ID and cost.
     * @param array<int, float> $percents Shares of the calls.
     * @param array<int, true> $kept Indexes of the calls kept.
     *
     * @return array<int, true> Indexes of the calls kept.
     */
    private static function linkToRoots(array $calls, array $percents, array $kept): array
    {
        $callees = \array_flip(\array_column($calls, 1));
        do {
            /** @var array<non-empty-string, list<non-empty-string>> $callers Callees of the kept calls by caller IDs */
            $callers = [];
            foreach ($kept as $i => $_) {
                $callers[$calls[$i][0]][] = $calls[$i][1];
            }

            /** @var list<non-empty-string> $stack */
            $stack = \array_values(\array_unique(\array_filter(
                \array_column($calls, 0),
                static fn(string $id): bool => !isset($callees[$id]),
            )));
            $reached = \array_fill_keys($stack, true);
            while (($callerId = \array_pop($stack)) !== null) {
                foreach ($callers[$callerId] ?? [] as $calleeId) {
                    if (!isset($reached[$calleeId])) {
                        $reached[$calleeId] = true;
                        $stack[] = $calleeId;
                    }
                }
            }

            $link = null;
            foreach ($calls as $i => [$callerId, $calleeId]) {
                isset($reached[$callerId]) && !isset($reached[$calleeId])
                    && ($link === null || $percents[$i] > $percents[$link])
                    and $link = $i;
            }
            $link === null or $kept[$link] = true;
        } while ($link !== null);

        return $kept;
    }

    /**
     * Calls between the visible functions, with their cost.
     *
     * A hidden function doesn't break the paths through it: its visible caller is linked to its visible callees.
     * The direct calls come from the call graph. The calls through hidden functions come from the call tree:
     * exact when the profiler records one, estimated by unfolding the graph otherwise.
     *
     * @param array<non-empty-string, mixed> $visible Keyed by function IDs.
     *
     * @return list<array{non-empty-string, non-empty-string, Cost}> Caller ID, callee ID and the summed cost.
     */
    private static function visibleCalls(Profile $profile, array $visible): array
    {
        /** @var array<non-empty-string, array{non-empty-string, non-empty-string, int, int, int, int, int}> $calls */
        $calls = [];
        /**
         * @param non-empty-string $callerId
         * @param non-empty-string $calleeId
         */
        $add = static function (string $callerId, string $calleeId, Cost $cost) use (&$calls): void {
            $key = "$callerId==>$calleeId";
            $sum = $calls[$key] ?? [$callerId, $calleeId, 0, 0, 0, 0, 0];
            $calls[$key] = [
                $callerId,
                $calleeId,
                $sum[2] + $cost->ct,
                $sum[3] + $cost->wt,
                $sum[4] + $cost->cpu,
                $sum[5] + $cost->mu,
                $sum[6] + $cost->pmu,
            ];
        };

        foreach ($profile->calls as $edge) {
            $edge->callerId !== null && isset($visible[$edge->callerId], $visible[$edge->calleeId])
                and $add($edge->callerId, $edge->calleeId, $edge->cost);
        }

        // Iterative: the depth follows the recursion in the profiled code, deeper than Xdebug's nesting limit
        /** @var list<array{Branch<Edge>, non-empty-string|null, bool}> $stack Node, nearest visible ancestor, whether a hidden one is between */
        $stack = \array_map(
            static fn(Branch $branch): array => [$branch, null, false],
            \array_reverse(\array_values($profile->tree()->root)),
        );
        while ($stack !== []) {
            [$branch, $ancestor, $throughHidden] = \array_pop($stack);
            $functionId = $branch->item->calleeId;
            $isVisible = isset($visible[$functionId]);

            $isVisible && $throughHidden && $ancestor !== null && $ancestor !== $functionId
                and $add($ancestor, $functionId, $branch->item->cost);

            foreach (\array_reverse($branch->children) as $child) {
                $stack[] = $isVisible ? [$child, $functionId, false] : [$child, $ancestor, $ancestor !== null];
            }
        }

        return \array_values(\array_map(
            static fn(array $sum): array => [
                $sum[0],
                $sum[1],
                new Cost(\max(0, $sum[2]), \max(0, $sum[3]), \max(0, $sum[4]), \max(0, $sum[5]), \max(0, $sum[6])),
            ],
            $calls,
        ));
    }

    /**
     * Places the nodes one after another from the start.
     *
     * @param list<Branch<Edge>> $branches
     *
     * @return list<array{Branch<Edge>, float, FlameChart\Span|null}> In reverse order, to pop the first one first.
     */
    private static function layOut(array $branches, float $start, ?FlameChart\Span $parent): array
    {
        $result = [];
        foreach ($branches as $branch) {
            $result[] = [$branch, $start, $parent];
            $start += self::duration($branch->item->cost);
        }

        return \array_reverse($result);
    }

    /**
     * @return float Milliseconds.
     */
    private static function duration(Cost $cost): float
    {
        return (float) $cost->wt / 1000.0;
    }

    /**
     * A sampling profiler counts the samples a function is on the stack in, not its calls.
     */
    private static function countsSamples(ProfilerPayload $payload): bool
    {
        return $payload->type === ProfilerPayloadType::Pprof
            || $payload->type === ProfilerPayloadType::Speedscope
            && ($payload->getProfile()->metadata['profile_type'] ?? null) === 'sampled';
    }

    /**
     * A profiler may not measure a metric at all: Xdebug has no CPU time and no peak memory.
     */
    private static function isMeasured(Peaks $peaks, string $metric): bool
    {
        return match ($metric) {
            'ct' => $peaks->ct > 0,
            'wt' => $peaks->wt > 0,
            'cpu' => $peaks->cpu > 0,
            'mu' => $peaks->mu > 0,
            'pmu' => $peaks->pmu > 0,
            default => false,
        };
    }

    /**
     * @return list<TopFunctions\Column> Inclusive and exclusive columns, none for a metric not measured.
     */
    private static function metricColumns(
        Peaks $peaks,
        string $key,
        string $label,
        string $description,
        string $format,
    ): array {
        if (!self::isMeasured($peaks, $key)) {
            return [];
        }

        return [
            new TopFunctions\Column(
                key: $key,
                label: $label,
                sortable: true,
                description: $description,
                values: [
                    new TopFunctions\Value(key: $key, format: $format),
                    new TopFunctions\Value(key: "p_$key", format: 'percent', type: 'sub'),
                ],
            ),
            new TopFunctions\Column(
                key: "excl_$key",
                label: "$label excl.",
                sortable: true,
                description: "Exclusive $description",
                values: [
                    new TopFunctions\Value(key: "excl_$key", format: $format),
                    new TopFunctions\Value(key: "p_excl_$key", format: 'percent', type: 'sub'),
                ],
            ),
        ];
    }

    private static function withPercents(Cost $cost, Peaks $peaks): Cost
    {
        $cost = clone $cost;
        $cost->setPercentsOf($peaks);

        return $cost;
    }

    private static function percentOf(Cost $cost, string $metric): float
    {
        return match ($metric) {
            'cpu' => $cost->p_cpu,
            'mu' => $cost->p_mu,
            'pmu' => $cost->p_pmu,
            'ct' => $cost->p_ct,
            default => $cost->p_wt,
        };
    }

    private static function costOf(Cost $cost, string $metric): int
    {
        return match ($metric) {
            'cpu' => $cost->cpu,
            'mu' => $cost->mu,
            'pmu' => $cost->pmu,
            'ct' => $cost->ct,
            default => $cost->wt,
        };
    }

    /**
     * @return array<string, TopFunctions\Func>
     */
    private function aggregateFunctions(Graph $graph, Peaks $totals): array
    {
        $functions = [];
        foreach ($graph->functions() as $functionId) {
            $calls = $graph->callers($functionId);
            $name = $calls[0]->callee;
            $func = $functions[$name] ??= new TopFunctions\Func($name, $totals);

            $func->addCall(
                $graph->inclusiveCost($functionId),
                \array_map(static fn(Edge $child): Cost => $child->cost, $graph->callees($functionId)),
                recursive: $calls[0]->isRecursive(),
            );
        }

        return $functions;
    }
}
