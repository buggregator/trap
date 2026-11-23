<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Csp\Frontend;

use Buggregator\Trap\Module\Csp\Frame as CspFrame;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\EventMapper;
use Buggregator\Trap\Server\Proto\Frame;
use Buggregator\Trap\Support\Uuid;

/**
 * Maps the reports of a request to the `csp` UI event: `{"format", "reports"}`, as buggregator/server stores it.
 *
 * @internal
 */
final class Mapper implements EventMapper
{
    public const TYPE = 'csp';

    #[\Override]
    public function map(Frame $frame): Event
    {
        \assert($frame instanceof CspFrame);

        return new Event(
            uuid: Uuid::generate(),
            type: self::TYPE,
            payload: ['format' => $frame->format, 'reports' => $frame->reports],
            timestamp: (float) $frame->time->format('U.u'),
            project: $frame->project,
        );
    }
}
