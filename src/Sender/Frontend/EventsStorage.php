<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Frontend;

use Buggregator\Trap\Config\Frontend\Buffer as Config;
use Countable;
use IteratorAggregate;

/**
 * @internal
 * @implements IteratorAggregate<Event>
 */
final class EventsStorage implements IteratorAggregate, Countable

{
    /**
     * @var array<non-empty-string, Event>
     */
    private array $events = [];

    public function __construct(
        private readonly Config $config = new Config(2),
    ) {
    }

    public function add(Event $event): void
    {
        $this->events[$event->uuid] = $event;
        if (\count($this->events) > $this->config->maxSize) {
            \array_shift($this->events);
        }
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

    /**
     * @return int<0, max>
     */
    public function count(): int
    {
        return \count($this->events);
    }
}
