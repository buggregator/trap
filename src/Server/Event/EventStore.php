<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Event;

use Buggregator\Trap\Server\Config\Events as Config;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Server\Proto\Frame;

/**
 * The events caught by this Trap process, one buffer for the web UI and the other event consumers:
 * an event deleted by one of them is gone for all.
 *
 * A container singleton, filled by the {@see EventCollector}.
 *
 * @internal
 * @implements \IteratorAggregate<non-empty-string, Event>
 */
final class EventStore implements \IteratorAggregate, \Countable
{
    /**
     * A random ID of the store, and so of the Trap process: cursors of another process mean nothing here.
     * @var non-empty-string
     */
    public readonly string $id;

    /**
     * Sorted by event timestamp in descending order when requested via the {@see getIterator()} method.
     * @var array<non-empty-string, Record>
     */
    private array $records = [];

    private bool $sorted = false;
    private bool $keepFrames = false;

    /** @var int<0, max> */
    private int $seq = 0;

    /** @var list<StoreListener> */
    private array $listeners = [];

    public function __construct(
        private readonly Config $config = new Config(),
        private readonly Logger $logger = new Logger(),
    ) {
        $this->id = \bin2hex(\random_bytes(4));
    }

    public function listen(StoreListener $listener): void
    {
        $this->listeners[] = $listener;
    }

    /**
     * Makes the records keep the frames of their events, for a consumer that reads them.
     * Otherwise the frames are dropped once mapped.
     */
    public function keepFrames(): void
    {
        $this->keepFrames = true;
    }

    /**
     * Adds the event, or replaces the stored one of its UUID: then the frames are added to the stored ones.
     *
     * @param Frame ...$frames Frames the event is made of; kept only after {@see keepFrames()}.
     */
    public function add(Event $event, Frame ...$frames): Record
    {
        $stored = $this->records[$event->uuid] ?? null;
        $record = new Record(
            $event,
            ++$this->seq,
            $this->keepFrames ? [...$stored?->frames ?? [], ...\array_values($frames)] : [],
        );
        $this->records[$event->uuid] = $record;
        $this->sorted = false;

        if ($stored !== null) {
            $this->notify(static fn(StoreListener $listener) => $listener->updated($record));
            return $record;
        }

        if (\count($this->records) > $this->config->maxEvents) {
            $oldest = $this->oldest();
            if ($oldest === $event->uuid) {
                // Older than all the kept ones: the listeners never see it
                unset($this->records[$oldest]);
                return $record;
            }

            $this->delete($oldest);
        }

        $this->notify(static fn(StoreListener $listener) => $listener->added($record));

        return $record;
    }

    public function clear(): void
    {
        $this->records = [];

        $this->notify(static fn(StoreListener $listener) => $listener->cleared());
    }

    public function delete(string $uuid): void
    {
        $record = $this->records[$uuid] ?? null;
        if ($record === null) {
            return;
        }

        unset($this->records[$uuid]);

        $this->notify(static fn(StoreListener $listener) => $listener->deleted($record));
    }

    public function get(string $uuid): ?Event
    {
        return $this->records[$uuid]->event ?? null;
    }

    public function record(string $uuid): ?Record
    {
        return $this->records[$uuid] ?? null;
    }

    /**
     * The sequence number of the last change, to pass to {@see changedSince()} later; 0 before the first one.
     *
     * @return int<0, max>
     */
    public function cursor(): int
    {
        return $this->seq;
    }

    /**
     * The events added or updated after the cursor and not deleted since then. Deletions leave no trace.
     *
     * @param int<0, max> $cursor A value of {@see cursor()}; 0 for all the events.
     *
     * @return list<Record> In the order of the changes.
     */
    public function changedSince(int $cursor): array
    {
        $records = \array_values(\array_filter(
            $this->records,
            static fn(Record $record): bool => $record->seq > $cursor,
        ));
        \usort($records, static fn(Record $a, Record $b): int => $a->seq <=> $b->seq);

        return $records;
    }

    /**
     * Events from the newest to the oldest one.
     *
     * @return \Traversable<non-empty-string, Event>
     */
    #[\Override]
    public function getIterator(): \Traversable
    {
        // Defers sorting of events until it is necessary.
        $this->sort();
        foreach ($this->records as $uuid => $record) {
            yield $uuid => $record->event;
        }
    }

    /**
     * @return int<0, max>
     */
    #[\Override]
    public function count(): int
    {
        return \count($this->records);
    }

    /**
     * A failing listener is logged: the store and the other listeners go on.
     *
     * @param \Closure(StoreListener): void $call
     */
    private function notify(\Closure $call): void
    {
        foreach ($this->listeners as $listener) {
            try {
                $call($listener);
            } catch (\Throwable $e) {
                $this->logger->exception($e, \sprintf('Event store listener %s failed', $listener::class));
            }
        }
    }

    /**
     * @return non-empty-string
     */
    private function oldest(): string
    {
        $uuid = \array_key_first($this->records);
        \assert($uuid !== null);
        $time = $this->records[$uuid]->event->timestamp;
        foreach ($this->records as $key => $record) {
            if ($record->event->timestamp < $time) {
                $uuid = $key;
                $time = $record->event->timestamp;
            }
        }

        return $uuid;
    }

    /**
     * Sort events by timestamp in descending order.
     */
    private function sort(): void
    {
        if (!$this->sorted) {
            \uasort(
                $this->records,
                static fn(Record $a, Record $b): int => $b->event->timestamp <=> $a->event->timestamp,
            );
            $this->sorted = true;
        }
    }
}
