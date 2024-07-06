<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Module\Profiler;

use Buggregator\Trap\Module\Frontend\Event;
use Buggregator\Trap\Module\Frontend\Module\Profiler\Message\CallGraph;
use Buggregator\Trap\Module\Frontend\Module\Profiler\Message\FlameChart;
use Buggregator\Trap\Module\Frontend\Module\Profiler\Message\TopFunctions;
use Buggregator\Trap\Proto\Frame\Profiler\Payload as ProfilerPayload;

/**
 * @internal
 */
final class Mapper
{
    /**
     * @param \Buggregator\Trap\Proto\Frame\Profiler $frame
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
        foreach ($profile->calls->all as $branch) {
            // todo: limit with 100 and sort
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
    public function callGraph(Event $event): CallGraph
    {
        return new CallGraph();
    }

    /**
     * @param Event<ProfilerPayload> $event
     */
    public function flameChart(Event $event): FlameChart
    {
        return new FlameChart();
    }
}
