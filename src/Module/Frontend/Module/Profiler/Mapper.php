<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Module\Profiler;

use Buggregator\Trap\Module\Frontend\Event;
use Buggregator\Trap\Module\Frontend\Module\Profiler\Message\CallGraph;
use Buggregator\Trap\Module\Frontend\Module\Profiler\Message\FlameChart;
use Buggregator\Trap\Module\Frontend\Module\Profiler\Message\TopFunctions;
use Buggregator\Trap\Module\Profiler\Struct\Branch;
use Buggregator\Trap\Module\Profiler\Struct\Edge;
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
     */
    public function topFunctions(Event $event, string $metric): TopFunctions
    {
        $profile = $event->payload->getProfile();

        // Get top
        $top = [];
        $topBranches = $profile->calls->top(
            100,
            match ($metric) {
                'ct' => static fn(Branch $a, Branch $b): int => $b->item->cost->ct <=> $a->item->cost->ct,
                'cpu' => static fn(Branch $a, Branch $b): int => $b->item->cost->cpu <=> $a->item->cost->cpu,
                'mu' => static fn(Branch $a, Branch $b): int => $b->item->cost->mu <=> $a->item->cost->mu,
                'pmu' => static fn(Branch $a, Branch $b): int => $b->item->cost->pmu <=> $a->item->cost->pmu,
                default => static fn(Branch $a, Branch $b): int => $b->item->cost->wt <=> $a->item->cost->wt,
            },
        );

        foreach ($topBranches as $branch) {
            $top[] = TopFunctions\Func::fromEdge($branch->item);
        }

        // Schema
        $schema = new TopFunctions\Schema([
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
            new TopFunctions\Column(
                key: 'cpu',
                label: 'CPU',
                sortable: true,
                description: 'CPU time (ms)',
                values: [
                    new TopFunctions\Value(key: 'cpu', format: 'ms'),
                    new TopFunctions\Value(key: 'p_cpu', format: 'percent', type: 'sub'),
                ],
            ),
            new TopFunctions\Column(
                key: 'wt',
                label: 'WT',
                sortable: true,
                description: 'Wall time (ms)',
                values: [
                    new TopFunctions\Value(key: 'wt', format: 'ms'),
                    new TopFunctions\Value(key: 'p_wt', format: 'percent', type: 'sub'),
                ],
            ),
            new TopFunctions\Column(
                key: 'mu',
                label: 'MU',
                sortable: true,
                description: 'Memory usage (bytes)',
                values: [
                    new TopFunctions\Value(key: 'mu', format: 'bytes'),
                    new TopFunctions\Value(key: 'p_mu', format: 'percent', type: 'sub'),
                ],
            ),
            new TopFunctions\Column(
                key: 'pmu',
                label: 'PMU',
                sortable: true,
                description: 'Peak memory usage (bytes)',
                values: [
                    new TopFunctions\Value(key: 'pmu', format: 'bytes'),
                    new TopFunctions\Value(key: 'p_pmu', format: 'percent', type: 'sub'),
                ],
            ),
        ]);

        return new TopFunctions(
            functions: $top,
            overallTotals: $profile->peaks,
            schema: $schema,
        );
    }

    /**
     * @param Event<ProfilerPayload> $event
     */
    public function callGraph(
        Event $event,
        float $threshold = 1,
        float $percentage = 15,
        string $metric = 'wt',
    ): CallGraph {
        return new CallGraph(
            toolbar: new CallGraph\Toolbar([
                new CallGraph\Button('CPU', 'cpu', 'CPU time (ms)'),
                new CallGraph\Button('Wall time', 'wt', 'Wall time (ms)'),
                new CallGraph\Button('Memory', 'mu', 'Memory usage (bytes)'),
                new CallGraph\Button('Peak memory', 'pmu', 'Peak memory usage (bytes)'),
            ]),
            nodes: [],
            edges: [],
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
        );
        /** @var array<array{Branch<Edge>, FlameChart\Span}> $queue */
        $queue = [[$r, $root]];

        /** @var array{Branch<Edge>, FlameChart\Span} $item */
        while ($item = \array_shift($queue)) {
            [$branch, $span] = $item;
            $s = $span->start;
            foreach ($branch->children as $child) {
                $d = $child->item->cost->wt / 1000;
                $childSpan = new FlameChart\Span(
                    name: $child->item->callee,
                    start: $s,
                    duration: $d,
                    cost: $child->item->cost,
                );
                $s += $d;
                $span->children[] = $childSpan;
                $queue[] = [$child, $childSpan];
            }
        }

        return new FlameChart($root);
    }
}
