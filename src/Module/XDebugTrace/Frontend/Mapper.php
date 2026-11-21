<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebugTrace\Frontend;

use Buggregator\Trap\Module\XDebugTrace\Frame;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\EventMapper;
use Buggregator\Trap\Server\Proto\Frame as ProtoFrame;

/**
 * The event carries the stored form, `{file, name, url, size, compressed, summary}`, in the list too:
 * the UI turns it into the preview card itself.
 *
 * @internal
 */
final class Mapper implements EventMapper
{
    #[\Override]
    public function map(ProtoFrame $frame): Event
    {
        \assert($frame instanceof Frame);

        return new Event(
            uuid: $frame->uuid,
            type: Frame::TYPE,
            payload: $frame->payload(),
            timestamp: (float) $frame->time->format('U.u'),
            project: $frame->project,
        );
    }
}
