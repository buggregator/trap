<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Html\Binary;

use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\EventMapper;
use Buggregator\Trap\Server\Proto\Frame;
use Buggregator\Trap\Support\Uuid;

/**
 * Maps the traffic no protocol took to an `html` event: the UI has no type of its own for raw bytes,
 * and an `html` event shows any markup, a hexdump and a viewer among them.
 *
 * A connection without bytes makes no event: port checks and TCP health checks connect and close, and would
 * push the events out of the store.
 *
 * @internal
 */
final class Mapper implements EventMapper
{
    #[\Override]
    public function map(Frame $frame): ?Event
    {
        \assert($frame instanceof Frame\Binary);

        if ($frame->getSize() === 0) {
            return null;
        }

        $uuid = Uuid::generate();

        return new Event(
            uuid: $uuid,
            type: 'html',
            payload: new Payload($uuid, $frame->stream, $frame->time),
            timestamp: (float) $frame->time->format('U.u'),
        );
    }
}
