<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Frontend\Mapper;

use Buggregator\Trap\Sender\Frontend\Event;
use Buggregator\Trap\Support\Uuid;

/**
 * @internal
 */
final class Profiler
{
    public function map(\Buggregator\Trap\Proto\Frame\Profiler\Payload $frame): Event
    {
        return new Event(
            uuid: Uuid::generate(),
            type: 'profiler',
            payload: $frame->payload,
            timestamp: (float)$frame->time->format('U.u'),
        );
    }
}
