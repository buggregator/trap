<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp\View;

use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Server\Event\Record;
use Buggregator\Trap\Server\Event\StoreListener;

/**
 * The summaries and the text views of the stored events, rendered once for the searches. An entry lives while
 * its record is the stored one; a text entry keeps at most the first {@see self::MAX_SEARCHED_BYTES} of the text:
 * what a search looks through.
 *
 * A full cache admits no more entries until the store deletes some: evicting the oldest one to admit the next
 * would, on a search that reads the events in the same order every time, evict each entry right before its turn.
 *
 * @internal
 */
final class TextCache implements StoreListener
{
    public const MAX_BYTES = 16 * 1024 * 1024;

    /** Of the text of each event. With the default 200 events of the store, all their texts fit the cache. */
    public const MAX_SEARCHED_BYTES = 64 * 1024;

    /** @var array<string, array{int, string, bool}> Event UUID => sequence number of its record, the kept text,
     *      and whether it is the whole text */
    private array $texts = [];

    /** @var array<string, array{int, non-empty-string}> Event UUID => sequence number of its record and summary */
    private array $summaries = [];

    /** @var int<0, max> */
    private int $bytes = 0;

    /**
     * @param int<0, max> $maxBytes Of all the kept texts.
     * @param int<0, max> $maxSearchedBytes Of the kept text of each event.
     */
    public function __construct(
        private readonly EventViews $views,
        EventStore $store,
        private readonly int $maxBytes = self::MAX_BYTES,
        private readonly int $maxSearchedBytes = self::MAX_SEARCHED_BYTES,
    ) {
        $store->listen($this);
    }

    /**
     * @return non-empty-string
     */
    public function summary(Record $record): string
    {
        $uuid = $record->event->uuid;
        $cached = $this->summaries[$uuid] ?? null;
        if ($cached !== null && $cached[0] === $record->seq) {
            return $cached[1];
        }

        $this->forgetSummary($uuid);
        $summary = $this->views->summary($record->event);
        $size = \strlen($summary);
        if ($this->bytes + $size <= $this->maxBytes) {
            $this->summaries[$uuid] = [$record->seq, $summary];
            $this->bytes += $size;
        }

        return $summary;
    }

    /**
     * The whole text view, rendered again when the cache keeps only its start.
     */
    public function text(Record $record): string
    {
        $cached = $this->cached($record);
        if ($cached !== null && $cached[2]) {
            return $cached[1];
        }

        $text = $this->views->text($record);
        $cached === null and $this->keep($record, $text);

        return $text;
    }

    /**
     * The text view cut to {@see self::MAX_SEARCHED_BYTES}, at a character boundary.
     */
    public function searchable(Record $record): string
    {
        return $this->cached($record)[1] ?? $this->keep($record, $this->views->text($record));
    }

    #[\Override]
    public function added(Record $record): void {}

    #[\Override]
    public function updated(Record $record): void
    {
        $this->forget($record->event->uuid);
    }

    #[\Override]
    public function deleted(Record $record): void
    {
        $this->forget($record->event->uuid);
    }

    #[\Override]
    public function cleared(): void
    {
        $this->texts = [];
        $this->summaries = [];
        $this->bytes = 0;
    }

    /**
     * @return int<0, max> The number of cached texts.
     */
    public function count(): int
    {
        return \count($this->texts);
    }

    /**
     * @return int<0, max> The size of the cached texts and summaries.
     */
    public function bytes(): int
    {
        return $this->bytes;
    }

    /**
     * @return array{int, string, bool}|null
     */
    private function cached(Record $record): ?array
    {
        $cached = $this->texts[$record->event->uuid] ?? null;
        if ($cached === null || $cached[0] === $record->seq) {
            return $cached;
        }

        $this->forgetText($record->event->uuid);

        return null;
    }

    /**
     * @return string The text cut for the searches, whether the cache has room for it or not.
     */
    private function keep(Record $record, string $text): string
    {
        $whole = \strlen($text) <= $this->maxSearchedBytes;
        $kept = $whole ? $text : \mb_strcut($text, 0, $this->maxSearchedBytes, 'UTF-8');
        $size = \strlen($kept);
        if ($this->bytes + $size <= $this->maxBytes) {
            $this->texts[$record->event->uuid] = [$record->seq, $kept, $whole];
            $this->bytes += $size;
        }

        return $kept;
    }

    private function forget(string $uuid): void
    {
        $this->forgetText($uuid);
        $this->forgetSummary($uuid);
    }

    private function forgetText(string $uuid): void
    {
        $cached = $this->texts[$uuid] ?? null;
        if ($cached === null) {
            return;
        }

        unset($this->texts[$uuid]);
        $this->bytes = \max(0, $this->bytes - \strlen($cached[1]));
    }

    private function forgetSummary(string $uuid): void
    {
        $cached = $this->summaries[$uuid] ?? null;
        if ($cached === null) {
            return;
        }

        unset($this->summaries[$uuid]);
        $this->bytes = \max(0, $this->bytes - \strlen($cached[1]));
    }
}
