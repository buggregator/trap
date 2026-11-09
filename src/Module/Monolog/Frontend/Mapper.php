<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Monolog\Frontend;

use Buggregator\Trap\Module\Frontend\Event;
use Buggregator\Trap\Module\Frontend\EventMapper;
use Buggregator\Trap\Module\Monolog\Frame as MonologFrame;
use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Support\Uuid;

/**
 * @internal
 */
final class Mapper implements EventMapper
{
    #[\Override]
    public function map(Frame $frame): Event
    {
        \assert($frame instanceof MonologFrame);

        return new Event(
            uuid: Uuid::uuid4(),
            type: 'monolog',
            payload: $frame->message,
            timestamp: (float) $frame->time->format('U.u'),
        );
    }
}
