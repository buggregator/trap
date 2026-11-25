<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Html\Frontend;

use Buggregator\Trap\Module\Html\Frame as HtmlFrame;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\EventMapper;
use Buggregator\Trap\Server\Proto\Frame;
use Buggregator\Trap\Support\Uuid;

/**
 * Maps a page to the `html` UI event, see {@see Payload}.
 *
 * @internal
 */
final class Mapper implements EventMapper
{
    public const TYPE = 'html';

    #[\Override]
    public function map(Frame $frame): Event
    {
        \assert($frame instanceof HtmlFrame);

        return new Event(
            uuid: Uuid::generate(),
            type: self::TYPE,
            payload: new Payload($frame->title, $frame->html),
            timestamp: (float) $frame->time->format('U.u'),
            project: $frame->project,
        );
    }
}
