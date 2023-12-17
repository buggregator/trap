<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Frontend;

use IteratorAggregate;

/**
 * @internal
 * @implements IteratorAggregate<Event>
 */
final class EventsStorage implements IteratorAggregate
{
    /**
     * @var array<non-empty-string, Event>
     */
    private array $events = [];

    public function add(Event $event): void
    {
        $this->events[$event->uuid] = $event;
    }

    public function clear(): void
    {
        $this->events = [];
    }

    /**
     * @return \Traversable<Event>
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->events);
    }

    public function delete(string $key): void
    {
        unset($this->events[$key]);
    }

    public function get(string $uuid): ?Event
    {
        return $this->events[$uuid] ?? null;
    }
}
