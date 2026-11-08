<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Module\Profiler;

use Buggregator\Trap\Module\Frontend\Event;
use Buggregator\Trap\Module\Frontend\Module\Profiler\Message\CallGraph;
use Buggregator\Trap\Module\Frontend\Module\Profiler\Message\FlameChart;
use Buggregator\Trap\Module\Frontend\Module\Profiler\Message\TopFunctions;
use Buggregator\Trap\Module\Profiler\Struct\Branch;
use Buggregator\Trap\Module\Profiler\Struct\Cost;
use Buggregator\Trap\Module\Profiler\Struct\Edge;
use Buggregator\Trap\Module\Profiler\Struct\Peaks;
use Buggregator\Trap\Module\Profiler\Struct\Tree;
use Buggregator\Trap\Proto\Frame\Profiler\Payload as ProfilerPayload;

/**
 * @internal
 */
final class Mapper
{
    /**
     * @return Event<ProfilerPayload>
     */
    public function frameToEvent(\Buggregator\Trap\Proto\Frame\Profiler $frame): Event
    {
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

        $top = [];
        foreach (\array_slice(\array_keys($order), 0, 100) as $name) {
            $top[] = $functions[$name];
        }

        return new TopFunctions(
            functions: $top,
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
                    description: 'Calls',
                    values: [new TopFunctions\Value(key: 'ct', format: 'number')],
                ),
                ...self::metricColumns('cpu', 'CPU', 'CPU time (ms)', 'ms'),
                ...self::metricColumns('wt', 'WT', 'Wall time (ms)', 'ms'),
                ...self::metricColumns('mu', 'MU', 'Memory usage (bytes)', 'bytes'),
                ...self::metricColumns('pmu', 'PMU', 'Peak memory usage (bytes)', 'bytes'),
            ]),
        );
    }

    /**
     * @param Event<ProfilerPayload> $event
     * @param float $threshold Nodes with a share of the metric not above it are hidden (%).
     * @param float $percentage Nodes with a share of the metric from it are colored (%).
     */
    public function callGraph(
        Event $event,
        float $threshold = 1,
        float $percentage = 15,
        string $metric = 'wt',
    ): CallGraph {
        $profile = $event->payload->getProfile();
        $nodes = [];
        $edges = [];
        /** @var array<int, non-empty-string> $registered Node IDs by Branch object IDs */
        $registered = [];

        foreach (self::walk($profile->calls) as $branch) {
            $cost = $branch->item->cost;
            $percent = self::percentOf($cost, $metric);
            $important = $percent >= $percentage;
            if (!$important && $percent <= $threshold) {
                continue;
            }

            $id = 'n' . (\count($registered) + 1);
            $registered[\spl_object_id($branch)] = $id;
            $color = $important ? Palette::callGraphNode($percent) : '#FFFFFF';

            $nodes[] = new CallGraph\Node(
                id: $id,
                name: $cost->ct > 0 ? \sprintf('%s (%dx)', $branch->item->callee, $cost->ct) : $branch->item->callee,
                cost: $cost,
                metrics: new CallGraph\Metrics(cost: self::costOf($cost, $metric), percents: $percent),
                color: $color,
                textColor: Palette::textOn($color),
            );

            $parentId = $branch->parent === null ? null : $registered[\spl_object_id($branch->parent)] ?? null;
            $parentId === null or $edges[] = new CallGraph\Edge(
                color: $color,
                label: (string) $percent . '%',
                source: $parentId,
                target: $id,
            );
        }

        return new CallGraph(
            toolbar: new CallGraph\Toolbar([
                new CallGraph\Button('CPU', 'cpu', 'CPU time (ms)'),
                new CallGraph\Button('Wall time', 'wt', 'Wall time (ms)'),
                new CallGraph\Button('Memory', 'mu', 'Memory usage (bytes)'),
                new CallGraph\Button('Peak memory', 'pmu', 'Peak memory usage (bytes)'),
            ]),
            nodes: $nodes,
            edges: $edges,
        );
    }

    /**
     * @param Event<ProfilerPayload> $event
     */
    public function flameChart(Event $event): FlameChart
    {
        $profile = $event->payload->getProfile();
        /** @var Branch<Edge> $r */
        $r = \reset($profile->calls->root);
        $root = new FlameChart\Span(
            name: $r->item->callee,
            start: 0,
            duration: $r->item->cost->wt / 1000,
            cost: $r->item->cost,
            color: Palette::flameChartSpan($r->item->cost->p_wt),
        );
        /** @var array<array{Branch<Edge>, FlameChart\Span}> $queue */
        $queue = [[$r, $root]];

        /** @var array{Branch<Edge>, FlameChart\Span} $item */
        while ($item = \array_shift($queue)) {
            [$branch, $span] = $item;
            $s = $span->start;
            foreach ($branch->children as $child) {
                $d = (float) $child->item->cost->wt / 1000.0;
                $childSpan = new FlameChart\Span(
                    name: $child->item->callee,
                    start: $s,
                    duration: $d,
                    cost: $child->item->cost,
                    color: Palette::flameChartSpan($child->item->cost->p_wt),
                );
                $s += $d;
                $span->children[] = $childSpan;
                $queue[] = [$child, $childSpan];
            }
        }

        return new FlameChart($root);
    }

    /**
     * @return array{TopFunctions\Column, TopFunctions\Column}
     */
    private static function metricColumns(string $key, string $label, string $description, string $format): array
    {
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

    /**
     * Depth-first, parents before children.
     *
     * Goes by the children links rather than {@see Tree::$all}: that index is keyed by the callee ID
     * and keeps a single branch of a function called from several callers.
     *
     * @param Tree<Edge> $tree
     *
     * @return \Generator<int, Branch<Edge>>
     */
    private static function walk(Tree $tree): \Generator
    {
        $stack = \array_reverse(\array_values($tree->root));
        while ($stack !== []) {
            /** @var Branch<Edge> $branch */
            $branch = \array_pop($stack);
            yield $branch;
            for ($i = \count($branch->children) - 1; $i >= 0; --$i) {
                $stack[] = $branch->children[$i];
            }
        }
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
     * @param Tree<Edge> $calls
     *
     * @return array<string, TopFunctions\Func>
     */
    private function aggregateFunctions(Tree $calls, Peaks $totals): array
    {
        $functions = [];
        foreach (self::walk($calls) as $branch) {
            $edge = $branch->item;
            $func = $functions[$edge->callee] ??= new TopFunctions\Func($edge->callee, $totals);

            // A recursive call is already a part of the outer call's inclusive cost
            $recursive = false;
            for ($p = $branch->parent; $p !== null; $p = $p->parent) {
                if ($p->item->callee === $edge->callee) {
                    $recursive = true;
                    break;
                }
            }

            $func->addCall(
                $edge->cost,
                \array_map(static fn(Branch $child): Cost => $child->item->cost, $branch->children),
                $recursive,
            );
        }

        return $functions;
    }
}
