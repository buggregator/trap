<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Event;

/**
 * Gets the changes of the {@see EventStore}, right after the store makes them.
 *
 * @internal
 */
interface StoreListener
{
    public function added(Record $record): void;

    /**
     * An event replaced the stored one of the same UUID, as a Ray request adding payloads to its event.
     */
    public function updated(Record $record): void;

    /**
     * Also called for the oldest event pushed out by a new one.
     */
    public function deleted(Record $record): void;

    /**
     * All the events are deleted at once; {@see deleted()} is not called for them.
     */
    public function cleared(): void;
}
