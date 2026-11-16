<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Event;

use Buggregator\Trap\Server\Proto\Frame;

/**
 * An event of the {@see EventStore} with what the store knows of it. A change of the event makes a new record.
 *
 * @internal
 */
final class Record
{
    /**
     * @param positive-int $seq Sequence number of the last change of the event, unique within the store.
     * @param list<Frame> $frames Frames the event is made of, in the order they came; empty for an event
     *        stored without them, or in a store that does not keep them.
     */
    public function __construct(
        public readonly Event $event,
        public readonly int $seq,
        public readonly array $frames,
    ) {}
}
