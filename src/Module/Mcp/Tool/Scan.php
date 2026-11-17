<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp\Tool;

use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Server\Event\Record;
use Buggregator\Trap\Support\TimeSlice;

/**
 * Walks the records a tool filters without freezing the server: rendering the text of a large event for a search
 * takes milliseconds, so in a fiber the walk lets the other fibers run every {@see self::$slice} seconds, and it
 * stops once a call has taken {@see self::$budget} seconds.
 *
 * While the walk is suspended the store may change: a record deleted or changed since the snapshot is left out.
 * A changed one has a later sequence number, so a cursor taken before the walk still gets it.
 *
 * @internal
 */
final class Scan
{
    /** Seconds a call may scan. */
    public const BUDGET = 5.0;

    /** Seconds of work between the suspensions. */
    public const SLICE = 0.02;

    public function __construct(
        public readonly float $budget = self::BUDGET,
        private readonly float $slice = self::SLICE,
    ) {}

    /**
     * @param list<Record> $records A snapshot of the store.
     *
     * @return \Generator<int, Record, mixed, Record|null> The records still stored unchanged. Returns the first
     *         record left unchecked when the budget ran out, null when all were checked. The first record is
     *         always checked, so a call continuing an interrupted walk makes progress.
     */
    public function walk(EventStore $store, array $records): \Generator
    {
        $deadline = \hrtime(true) + (int) ($this->budget * 1e9);
        $slice = new TimeSlice($this->slice, checkEvery: 1);
        foreach ($records as $i => $record) {
            if ($i > 0 && \hrtime(true) >= $deadline) {
                return $record;
            }

            if ($store->record($record->event->uuid)?->seq !== $record->seq) {
                continue;
            }

            yield $record;
            $slice->tick();
        }

        return null;
    }
}
