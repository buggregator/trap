<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Monolog\Frontend;

use Buggregator\Trap\Module\Frontend\Event;
use Buggregator\Trap\Module\Monolog\Frame as MonologFrame;
use Buggregator\Trap\Support\Uuid;

/**
 * @internal
 */
final class Mapper
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
