<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Frontend\Message;

use Buggregator\Trap\Sender\Frontend\Event;

/**
 * @internal
 */
final class EventCollection implements \JsonSerializable
{
    /**
     * @param array<array-key, Event> $events
     * @param array<array-key, mixed> $meta
     */
    public function __construct(
        public readonly array $events,
        public readonly array $meta = [],
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'data' => $this->events,
            'meta' => $this->meta + ['grid' => []],
        ];
    }
}
