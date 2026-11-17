<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp\Tool;

use Buggregator\Trap\Server\Event\Record;

/**
 * What {@see ListEvents::collect()} called again with the same filter and text length can reuse: whether a record
 * matches, and the listed entry of a matching one. Both depend only on the record, whose content keeps its sequence
 * number, so an entry holds while the record is the stored one — provided the event views render the same event
 * the same way, as {@see \Buggregator\Trap\Module\Mcp\View\TextCache} also takes.
 *
 * Without the memo a wait searching large texts would check every record since its `since` again on each change.
 *
 * @internal
 *
 * @psalm-import-type ListedEvent from ListEvents
 */
final class CollectMemo
{
    /** @var array<string, array{int, bool}> Event UUID => sequence number of its record and whether it matches */
    private array $matches = [];

    /** @var array<string, array{int, ListedEvent}> Event UUID => sequence number of its record and its entry */
    private array $entries = [];

    /**
     * The entries of the current pass: the ones left out of a pass go, so the memo keeps no more texts
     * than a result holds.
     *
     * @var array<string, array{int, ListedEvent}>
     */
    private array $described = [];

    /**
     * The verdicts of the current pass: a pass that walks all the records drops the ones of records it didn't
     * meet, deleted or evicted, so the memo holds no more verdicts than the store holds records.
     *
     * @var array<string, array{int, bool}>
     */
    private array $checked = [];

    public function matches(Record $record, EventFilter $filter): bool
    {
        $uuid = $record->event->uuid;
        $known = $this->matches[$uuid] ?? null;
        if ($known === null || $known[0] !== $record->seq) {
            $known = [$record->seq, $filter->matches($record)];
            $this->matches[$uuid] = $known;
        }

        $this->checked[$uuid] = $known;
        return $known[1];
    }

    /**
     * @param \Closure(Record): ListedEvent $describe
     *
     * @return ListedEvent
     */
    public function describe(Record $record, \Closure $describe): array
    {
        $uuid = $record->event->uuid;
        $entry = $this->entries[$uuid] ?? null;
        if ($entry === null || $entry[0] !== $record->seq) {
            $entry = [$record->seq, $describe($record)];
        }

        $this->described[$uuid] = $entry;

        return $entry[1];
    }

    /**
     * Ends a pass: keeps the entries it described for the next one.
     *
     * @param bool $complete Whether the pass walked all the records; a pass cut short keeps the verdicts
     *        of the records it didn't reach.
     */
    public function pass(bool $complete = true): void
    {
        $this->entries = $this->described;
        $this->described = [];
        $complete and $this->matches = $this->checked;
        $this->checked = [];
    }
}
