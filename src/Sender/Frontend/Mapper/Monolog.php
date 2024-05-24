<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Frontend\Mapper;

use Buggregator\Trap\Proto\Frame\Monolog as MonologFrame;
use Buggregator\Trap\Sender\Frontend\Event;
use Buggregator\Trap\Support\Uuid;

/**
 * @internal
 */
final class Monolog
{
    public function map(MonologFrame $frame): Event
    {
        return new Event(
            uuid: Uuid::uuid4(),
            type: 'monolog',
            payload: $frame->message,
            timestamp: (float) $frame->time->format('U.u'),
        );
    }
}
