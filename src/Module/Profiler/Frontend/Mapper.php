<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Frontend;

use Buggregator\Trap\Module\Profiler\Frame as ProfilerFrame;
use Buggregator\Trap\Module\Profiler\Frame\Payload as ProfilerPayload;
use Buggregator\Trap\Module\Profiler\Frame\Type as ProfilerPayloadType;
use Buggregator\Trap\Module\Profiler\Frontend\Message\CallGraph;
use Buggregator\Trap\Module\Profiler\Frontend\Message\Comparison;
use Buggregator\Trap\Module\Profiler\Frontend\Message\FlameChart;
use Buggregator\Trap\Module\Profiler\Frontend\Message\TopFunctions;
use Buggregator\Trap\Module\Profiler\Struct\Branch;
use Buggregator\Trap\Module\Profiler\Struct\Cost;
use Buggregator\Trap\Module\Profiler\Struct\Edge;
use Buggregator\Trap\Module\Profiler\Struct\Graph;
use Buggregator\Trap\Module\Profiler\Struct\Peaks;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Module\Profiler\Struct\Recursion;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\EventMapper;
use Buggregator\Trap\Server\Event\Facets;
use Buggregator\Trap\Server\Proto\Frame;

/**
 * @internal
 */
final class Mapper implements EventMapper
{
    public const SAMPLES = 'samples';

    /** Calls SPX saw at its sampling ticks: a call that stays on the stack across ticks counts once. */
    public const SAMPLED_CALLS = 'sampled calls';

    /**
     * A sampling profiler counts the samples a function is on the stack in, not its calls; a pprof profile may count
     * something else by its sample type, such as the allocated objects of a heap profile.
     *
     * @return non-empty-string|null The sample type, {@see self::SAMPLES} for the samples,
     *         {@see self::SAMPLED_CALLS} for SPX in sampling mode, null for the calls.
     */
    public static function countType(ProfilerPayload $payload): ?string
    {
        $metadata = $payload->getProfile()->metadata;
        if ($payload->type === ProfilerPayloadType::Pprof) {
            /** @var mixed $type */
            $type = $metadata['count_type'] ?? null;

            return \is_string($type) && $type !== '' && \preg_match('/samples?$/i', $type) !== 1 ? $type : self::SAMPLES;
        }

        return match ($payload->type) {
            ProfilerPayloadType::Speedscope => ($metadata['profile_type'] ?? null) === 'sampled' ? self::SAMPLES : null,
            ProfilerPayloadType::SPX => isset($metadata['sampling_period']) ? self::SAMPLED_CALLS : null,
            ProfilerPayloadType::Phpspy, ProfilerPayloadType::Collapsed => self::SAMPLES,
            default => null,
        };
    }

    /**
     * @return Event<ProfilerPayload>
     */
    #[\Override]
    public function map(Frame $frame): Event
    {
        \assert($frame instanceof ProfilerFrame);

        $profile = $frame->payload->getProfile();

        return new Event(
            uuid: $profile->uuid,
            type: 'profiler',
            payload: $frame->payload,
            timestamp: (float) $frame->time->format('U.u'),
            facets: (new Facets())
                ->add(Facets::HOST, $profile->metadata['hostname'] ?? null)
                ->addTags($profile->tags)
                ->toArray(),
        );
    }

    /**
     * @param Event<ProfilerPayload> $event
     * @param string $metric Sorting key: `wt`, `excl_wt`, `ct`, etc.
     */
    public function topFunctions(Event $event, string $metric): TopFunctions
    {
        $profile = $event->payload->getProfile();
        $functions = TopFunctions\Func::aggregate($profile->calls, $profile->peaks);

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
                    description: match ($countType = self::countType($event->payload)) {
                        null => 'Calls',
                        self::SAMPLES => 'Samples with the function on the stack',
                        self::SAMPLED_CALLS => 'Calls seen at the sampling ticks; a call over several ticks counts once',
                        default => "$countType of the function and its callees",
                    },
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
     * @param bool $collapseRecursion A node per function, its recursion levels merged into it: its cost is the one
     *        of its outermost calls, and direct recursion is a call from the node to itself. Otherwise a node
     *        per recursion level, see {@see Recursion}.
     */
    public function callGraph(
        Event $event,
        float $threshold = 1,
        float $percentage = 15,
        string $metric = 'wt',
        bool $collapseRecursion = false,
    ): CallGraph {
        $profile = $event->payload->getProfile();
        $graph = $profile->calls;
        $countType = self::countType($event->payload);
        // Every call counts once; a sample counts once however deep the recursion it is in
        $callCounts = $countType === null || $countType === self::SAMPLED_CALLS;
        $nodes = [];
        $edges = [];

        /** @var array<array-key, non-empty-list<non-empty-string>> $groups Function IDs by the nodes they make */
        $groups = [];
        /** @var array<non-empty-string, int<0, max>> $levels Recursion levels by function IDs */
        $levels = [];
        foreach ($graph->functions() as $functionId) {
            $name = $graph->callers($functionId)[0]->callee;
            $levels[$functionId] = Recursion::level($functionId, $name);
            $groups[$collapseRecursion ? $name : $functionId][] = $functionId;
        }

        /** @var array<non-empty-string, non-empty-string> $visible Node IDs by function IDs */
        $visible = [];
        /** @var array<non-empty-string, string> $colors Colors by node IDs */
        $colors = [];
        foreach ($groups as $functionIds) {
            [$cost, $recursive] = self::nodeCost($graph, $functionIds, $levels, $callCounts);
            $cost = self::withPercents($cost, $profile->peaks);
            $percent = self::percentOf($cost, $metric);
            $important = $percent >= $percentage;
            if (!$important && $percent <= $threshold) {
                continue;
            }

            $id = 'n' . (\count($colors) + 1);
            $color = $important ? Palette::callGraphNode($percent) : '#FFFFFF';
            $colors[$id] = $color;
            foreach ($functionIds as $functionId) {
                $visible[$functionId] = $id;
            }
            $name = $graph->callers($functionIds[0])[0]->callee;

            $nodes[] = new CallGraph\Node(
                id: $id,
                name: match (true) {
                    $cost->ct === 0 => $name,
                    $countType === null && $recursive > 0 => \sprintf('%s (%dx, %d recursive)', $name, $cost->ct, $recursive),
                    $countType === null => \sprintf('%s (%dx)', $name, $cost->ct),
                    $countType === self::SAMPLES
                        => \sprintf('%s (%d %s)', $name, $cost->ct, $cost->ct === 1 ? 'sample' : 'samples'),
                    $countType === self::SAMPLED_CALLS && $recursive > 0
                        => \sprintf('%s (%d sampled calls, %d recursive)', $name, $cost->ct, $recursive),
                    $countType === self::SAMPLED_CALLS
                        => \sprintf('%s (%d sampled %s)', $name, $cost->ct, $cost->ct === 1 ? 'call' : 'calls'),
                    default => \sprintf('%s (%d %s)', $name, $cost->ct, $countType),
                },
                cost: $cost,
                metrics: new CallGraph\Metrics(cost: self::costOf($cost, $metric), percents: $percent),
                color: $color,
                textColor: Palette::textOn($color),
            );
        }

        $calls = $collapseRecursion && $profile->exactTree
            ? self::treeCalls($profile, $visible, $callCounts)
            : self::nodeCalls(self::visibleCalls($profile, $visible), $visible, $levels, $callCounts);
        $percents = \array_map(
            static fn(array $call): float => self::percentOf(self::withPercents($call[2], $profile->peaks), $metric),
            $calls,
        );
        /** @var array<non-empty-string, int> $heaviest Index of the heaviest call by callee nodes */
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
                color: $colors[$calleeId],
                label: (string) $percents[$i] . '%',
                source: $callerId,
                target: $calleeId,
            );
        }

        return new CallGraph(
            recursion: $collapseRecursion ? CallGraph::RECURSION_COLLAPSED : CallGraph::RECURSION_LEVELS,
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
     * @param string $metric The widths of the spans: `wt` and `cpu` in ms, `mu` and `pmu` in KiB;
     *        a negative memory change is drawn empty.
     */
    public function flameChart(Event $event, string $metric = 'wt'): FlameChart
    {
        \in_array($metric, ['wt', 'cpu', 'mu', 'pmu'], true) or $metric = 'wt';
        $roots = [];

        // Iterative: the depth follows the recursion in the profiled code, deeper than Xdebug's nesting limit
        /** @var list<array{Branch<Edge>, float, FlameChart\Span|null}> $stack Node, its start and parent span */
        $stack = self::layOut(\array_values($event->payload->getProfile()->tree()->root), 0.0, null, $metric);
        while ($stack !== []) {
            [$branch, $start, $parent] = \array_pop($stack);
            $cost = $branch->item->cost;
            $span = new FlameChart\Span(
                name: $branch->item->callee,
                start: $start,
                duration: self::duration($cost, $metric),
                cost: $cost,
                color: Palette::flameChartSpan(self::percentOf($cost, $metric)),
            );
            $parent === null ? $roots[] = $span : $parent->children[] = $span;
            \array_push($stack, ...self::layOut($branch->children, $start, $span, $metric));
        }

        return new FlameChart($roots);
    }

    /**
     * @param Event<ProfilerPayload> $base
     * @param Event<ProfilerPayload> $compare
     */
    public function compare(Event $base, Event $compare): Comparison
    {
        $a = $base->payload->getProfile();
        $b = $compare->payload->getProfile();

        return new Comparison(
            TopFunctions\Func::aggregate($a->calls, $a->peaks),
            TopFunctions\Func::aggregate($b->calls, $b->peaks),
        );
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
        // A recursive root calls itself, and is a root still
        $callees = [];
        foreach ($calls as [$callerId, $calleeId]) {
            $callerId === $calleeId or $callees[$calleeId] = true;
        }
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
     * Inclusive cost of the functions of a node: of its outermost calls, which take the deeper recursion levels in.
     *
     * @param non-empty-list<non-empty-string> $functionIds
     * @param array<non-empty-string, int<0, max>> $levels Recursion levels by function IDs.
     * @param bool $callCounts The count is of calls, which are apart at every level; otherwise of samples, which
     *        the outermost level has all.
     *
     * @return array{Cost, int<0, max>} The cost, and the calls of the deeper levels when the count is of calls.
     */
    private static function nodeCost(Graph $graph, array $functionIds, array $levels, bool $callCounts): array
    {
        $outermost = $functionIds[0];
        $ct = 0;
        foreach ($functionIds as $functionId) {
            $levels[$functionId] < $levels[$outermost] and $outermost = $functionId;
            $ct = Cost::sum($ct, $graph->inclusiveCost($functionId)->ct);
        }
        $cost = $graph->inclusiveCost($outermost);
        if (!$callCounts || \count($functionIds) === 1) {
            return [$cost, 0];
        }

        return [new Cost($ct, $cost->wt, $cost->cpu, $cost->mu, $cost->pmu), \max(0, $ct - $cost->ct)];
    }

    /**
     * Calls between the nodes, exact, from a recorded call tree: a call made inside an outer call of the same nodes
     * adds its count when the count is of calls, and nothing else, as the cost of the outer call takes it in.
     * A hidden function doesn't break the paths through it, as in {@see self::visibleCalls()}.
     *
     * @param array<non-empty-string, non-empty-string> $nodes Node IDs by the function IDs of the visible nodes.
     *
     * @return list<array{non-empty-string, non-empty-string, Cost}> Between node IDs.
     */
    private static function treeCalls(Profile $profile, array $nodes, bool $callCounts): array
    {
        /** @var array<non-empty-string, array{non-empty-string, non-empty-string, int<0, max>, int<0, max>, int<0, max>, int<0, max>, int<0, max>}> $sums */
        $sums = [];
        /** @var array<non-empty-string, int<1, max>> $open Calls of each pair on the path from the root */
        $open = [];

        // Iterative: the depth follows the recursion in the profiled code. A string entry closes a call of a pair.
        /** @var list<array{Branch<Edge>, non-empty-string|null}|non-empty-string> $stack Node, nearest visible ancestor */
        $stack = \array_map(
            static fn(Branch $branch): array => [$branch, null],
            \array_reverse(\array_values($profile->tree()->root)),
        );
        while ($stack !== []) {
            $entry = \array_pop($stack);
            if (\is_string($entry)) {
                if ($open[$entry] === 1) {
                    unset($open[$entry]);
                } else {
                    --$open[$entry];
                }
                continue;
            }

            [$branch, $ancestor] = $entry;
            $node = $nodes[$branch->item->calleeId] ?? null;
            $children = \array_reverse($branch->children);
            if ($node !== null && $ancestor !== null) {
                $pair = "$ancestor==>$node";
                $cost = $branch->item->cost;
                $outer = !isset($open[$pair]);
                $sum = $sums[$pair] ?? [$ancestor, $node, 0, 0, 0, 0, 0];
                ($outer || $callCounts) and $sum[2] = Cost::sum($sum[2], $cost->ct);
                if ($outer) {
                    $sum[3] = Cost::sum($sum[3], $cost->wt);
                    $sum[4] = Cost::sum($sum[4], $cost->cpu);
                    $sum[5] = Cost::sum($sum[5], $cost->mu);
                    $sum[6] = Cost::sum($sum[6], $cost->pmu);
                }
                $sums[$pair] = $sum;
                if ($children !== []) {
                    $open[$pair] = ($open[$pair] ?? 0) + 1;
                    $stack[] = $pair;
                }
            }
            foreach ($children as $child) {
                $stack[] = [$child, $node ?? $ancestor];
            }
        }

        // A call may have freed more memory than it took: as in visibleCalls(), no negative cost
        return \array_values(\array_map(
            static fn(array $sum): array => [
                $sum[0],
                $sum[1],
                new Cost(\max(0, $sum[2]), \max(0, $sum[3]), \max(0, $sum[4]), \max(0, $sum[5]), \max(0, $sum[6])),
            ],
            $sums,
        ));
    }

    /**
     * Calls between the nodes, summed over the recursion levels of their functions, by the levels alone when no
     * recorded tree tells how the calls nest, see {@see Recursion::nested()}: a call taken as made inside another call
     * of the same nodes adds its count when the count is of calls, and nothing else.
     *
     * @param list<array{non-empty-string, non-empty-string, Cost}> $calls Between function IDs.
     * @param array<non-empty-string, non-empty-string> $nodes Node IDs by function IDs.
     * @param array<non-empty-string, int<0, max>> $levels Recursion levels by function IDs.
     *
     * @return list<array{non-empty-string, non-empty-string, Cost}> Between node IDs.
     */
    private static function nodeCalls(array $calls, array $nodes, array $levels, bool $callCounts): array
    {
        /** @var array<non-empty-string, list<array{non-empty-string, non-empty-string, Cost}>> $pairs */
        $pairs = [];
        foreach ($calls as $call) {
            $pairs[$nodes[$call[0]] . '==>' . $nodes[$call[1]]][] = $call;
        }

        $result = [];
        foreach ($pairs as $pair) {
            $nested = \count($pair) === 1 ? [] : Recursion::nested(\array_map(
                static fn(array $call): array => [$levels[$call[0]], $levels[$call[1]]],
                $pair,
            ));
            $ct = $wt = $cpu = $mu = $pmu = 0;
            foreach ($pair as $i => [, , $cost]) {
                $outer = !isset($nested[$i]);
                ($outer || $callCounts) and $ct = Cost::sum($ct, $cost->ct);
                if ($outer) {
                    $wt = Cost::sum($wt, $cost->wt);
                    $cpu = Cost::sum($cpu, $cost->cpu);
                    $mu = Cost::sum($mu, $cost->mu);
                    $pmu = Cost::sum($pmu, $cost->pmu);
                }
            }
            $result[] = [$nodes[$pair[0][0]], $nodes[$pair[0][1]], new Cost($ct, $wt, $cpu, $mu, $pmu)];
        }

        return $result;
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
                Cost::saturate($sum[2] + $cost->ct),
                Cost::saturate($sum[3] + $cost->wt),
                Cost::saturate($sum[4] + $cost->cpu),
                Cost::saturate($sum[5] + $cost->mu),
                Cost::saturate($sum[6] + $cost->pmu),
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
    private static function layOut(array $branches, float $start, ?FlameChart\Span $parent, string $metric): array
    {
        $result = [];
        foreach ($branches as $branch) {
            $result[] = [$branch, $start, $parent];
            $start += self::duration($branch->item->cost, $metric);
        }

        return \array_reverse($result);
    }

    /**
     * @return float Milliseconds of a time metric, KiB of a memory one.
     */
    private static function duration(Cost $cost, string $metric): float
    {
        return (float) \max(0, self::costOf($cost, $metric)) / (\in_array($metric, ['mu', 'pmu'], true) ? 1024.0 : 1000.0);
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
}
