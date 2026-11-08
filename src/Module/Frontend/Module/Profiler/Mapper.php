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
use Buggregator\Trap\Module\Profiler\Struct\Graph;
use Buggregator\Trap\Module\Profiler\Struct\Peaks;
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
     * @param float $threshold Functions with a share of the metric not above it are hidden (%).
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
                name: $cost->ct > 0 ? \sprintf('%s (%dx)', $name, $cost->ct) : $name,
                cost: $cost,
                metrics: new CallGraph\Metrics(cost: self::costOf($cost, $metric), percents: $percent),
                color: $color,
                textColor: Palette::textOn($color),
            );
        }

        foreach ($graph as $edge) {
            if ($edge->callerId === null || !isset($visible[$edge->callerId], $visible[$edge->calleeId])) {
                continue;
            }

            $edges[] = new CallGraph\Edge(
                color: $visible[$edge->calleeId][1],
                label: (string) self::percentOf($edge->cost, $metric) . '%',
                source: $visible[$edge->callerId][0],
                target: $visible[$edge->calleeId][0],
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
        $roots = [];
        $start = 0.0;
        foreach ($event->payload->getProfile()->tree()->root as $branch) {
            $roots[] = $span = self::span($branch, $start);
            $start += $span->duration;
        }

        return new FlameChart($roots);
    }

    /**
     * @param Branch<Edge> $branch
     */
    private static function span(Branch $branch, float $start): FlameChart\Span
    {
        $cost = $branch->item->cost;
        $span = new FlameChart\Span(
            name: $branch->item->callee,
            start: $start,
            duration: (float) $cost->wt / 1000.0,
            cost: $cost,
            color: Palette::flameChartSpan($cost->p_wt),
        );

        $childStart = $start;
        foreach ($branch->children as $child) {
            $span->children[] = $childSpan = self::span($child, $childStart);
            $childStart += $childSpan->duration;
        }

        return $span;
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
                // A nested recursion level like `foo@1` is a part of the outer level's inclusive cost
                recursive: $functionId !== $name,
            );
        }

        return $functions;
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

    private static function withPercents(Cost $cost, Peaks $peaks): Cost
    {
        $cost = clone $cost;
        $cost->p_ct = self::percent($cost->ct, $peaks->ct);
        $cost->p_wt = self::percent($cost->wt, $peaks->wt);
        $cost->p_cpu = self::percent($cost->cpu, $peaks->cpu);
        $cost->p_mu = self::percent($cost->mu, $peaks->mu);
        $cost->p_pmu = self::percent($cost->pmu, $peaks->pmu);

        return $cost;
    }

    private static function percent(int $value, int $total): float
    {
        return $total > 0 ? \round((float) $value / (float) $total * 100.0, 3) : 0.0;
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
