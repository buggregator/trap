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
    public function topFunctions(Event $event): TopFunctions
    {
        return new TopFunctions();
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
