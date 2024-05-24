<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Frontend;

use Buggregator\Trap\Config\Server\Frontend\EventStorage as Config;
use IteratorAggregate;

/**
 * @internal
 * @implements IteratorAggregate<Event>
 */
final class EventStorage implements \IteratorAggregate, \Countable
{
    /**
     * Events. Will be sorted by timestamp in descending order when requested via the {@see getIterator()} method.
     * @var array<non-empty-string, Event>
     */
    private array $events = [];

    private bool $sorted = false;

    public function __construct(
        private readonly Config $config = new Config(),
    ) {}

    public function add(Event $event): void
    {
        $this->events[$event->uuid] = $event;
        $this->sorted = false;

        if (\count($this->events) > $this->config->maxEvents) {
            // find most old event and remove it
            $k = $event->uuid;
            $t = $event->timestamp;
            foreach ($this->events as $uuid => $e) {
                if ($e->timestamp < $t) {
                    $k = $uuid;
                    $t = $e->timestamp;
                }
            }
            unset($this->events[$k]);
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
        // Defers sorting of events until it is necessary.
        $this->sort();
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

    /**
     * Sort events by timestamp in descending order.
     */
    private function sort(): void
    {
        if (!$this->sorted) {
            \uasort($this->events, static fn(Event $a, Event $b) => $b->timestamp <=> $a->timestamp);
            $this->sorted = true;
        }
    }
}
