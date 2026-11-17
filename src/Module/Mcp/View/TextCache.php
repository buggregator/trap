<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp\View;

use Buggregator\Trap\Server\Config\Events as Config;
use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Server\Event\Record;
use Buggregator\Trap\Server\Event\StoreListener;
use Buggregator\Trap\Support\Json;

/**
 * The summaries and the text views of the stored events, rendered once for the searches and the cut texts.
 * An entry lives while its record is the stored one; a text entry keeps at most the first
 * {@see self::MAX_SEARCHED_BYTES} of the text, what a search looks through, and the length of the whole text.
 *
 * A full cache admits no more entries until the store deletes some: evicting the oldest one to admit the next
 * would, on a search that reads the events in the same order every time, evict each entry right before its turn.
 *
 * @internal
 */
final class TextCache implements StoreListener
{
    /** The least size of the cache, whatever the size of the store. */
    public const MIN_BYTES = 16 * 1024 * 1024;

    /** The largest size of the cache, whatever the size of the store. */
    public const MAX_BYTES = 256 * 1024 * 1024;

    /** Of the text of each event. */
    public const MAX_SEARCHED_BYTES = 64 * 1024;

    /**
     * Bytes of the frames of the events {@see warm()} renders, or of the JSON payload of an event stored without
     * frames. A 2.7 MB dump takes about half a second to render, and the warming blocks the server meanwhile.
     */
    public const MAX_WARMED_BYTES = 256 * 1024;

    /** Room for the summary of each event in the size of the cache: a summary takes 160 characters at most. */
    public const SUMMARY_BYTES = 1024;

    /**
     * @var array<string, array{int, string, int<0, max>, int<0, max>}> Event UUID => sequence number of its record,
     *      the kept start of the text, its length in characters, and the length of the whole text in characters
     */
    private array $texts = [];

    /** @var array<string, array{int, non-empty-string}> Event UUID => sequence number of its record and summary */
    private array $summaries = [];

    /** @var array<string, Record> Event UUID => the stored record not rendered yet, in the order of the changes */
    private array $cold = [];

    /** @var int<0, max> */
    private int $bytes = 0;

    /** @var int<0, max> */
    private readonly int $maxBytes;

    /**
     * @param int<0, max>|null $maxBytes Of all the kept texts and summaries; null for room for every event of the
     *        store, between {@see self::MIN_BYTES} and {@see self::MAX_BYTES}.
     * @param int<0, max> $maxSearchedBytes Of the kept text of each event.
     */
    public function __construct(
        private readonly EventViews $views,
        EventStore $store,
        Config $config = new Config(),
        ?int $maxBytes = null,
        private readonly int $maxSearchedBytes = self::MAX_SEARCHED_BYTES,
    ) {
        $this->maxBytes = $maxBytes ?? \min(
            self::MAX_BYTES,
            \max(self::MIN_BYTES, $config->maxEvents * ($maxSearchedBytes + self::SUMMARY_BYTES)),
        );
        foreach ($store->changedSince(0) as $record) {
            $this->cold[$record->event->uuid] = $record;
        }

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
     * The start of the text view: from the cache when it keeps enough of the text, rendered again otherwise.
     *
     * @param int<0, max> $chars
     *
     * @return array{string, int<0, max>} The first `$chars` characters, or the whole text when shorter;
     *         the length of the whole text in characters.
     */
    public function prefix(Record $record, int $chars): array
    {
        $cached = $this->cached($record);
        if ($cached === null || ($cached[2] < $chars && $cached[2] < $cached[3])) {
            $text = $this->views->text($record);
            $cached === null and $this->keep($record, $text);

            return [\mb_substr($text, 0, $chars), \mb_strlen($text)];
        }

        return [$cached[2] > $chars ? \mb_substr($cached[1], 0, $chars) : $cached[1], $cached[3]];
    }

    /**
     * The text view cut to {@see self::MAX_SEARCHED_BYTES}, at a character boundary.
     */
    public function searchable(Record $record): string
    {
        return ($this->cached($record) ?? $this->keep($record, $this->views->text($record)))[1];
    }

    /**
     * Renders the summaries and the texts not cached yet, oldest change first, so that a search finds them ready.
     * Skips the events larger than {@see self::MAX_WARMED_BYTES}: they are rendered when a tool reads them.
     *
     * @param float $seconds Time to render for; at least one event is taken.
     *
     * @return bool Whether events are left to render; false while the cache has no room for another text.
     */
    public function warm(float $seconds): bool
    {
        $deadline = \hrtime(true) + (int) ($seconds * 1e9);
        foreach ($this->cold as $uuid => $record) {
            // The rendered text might not be kept
            if ($this->bytes + $this->maxSearchedBytes + self::SUMMARY_BYTES > $this->maxBytes) {
                return false;
            }

            unset($this->cold[$uuid]);
            if (!self::isLarge($record)) {
                $this->summary($record);
                $this->searchable($record);
            }

            if (\hrtime(true) >= $deadline) {
                break;
            }
        }

        return $this->cold !== [];
    }

    #[\Override]
    public function added(Record $record): void
    {
        $this->cold[$record->event->uuid] = $record;
    }

    #[\Override]
    public function updated(Record $record): void
    {
        $uuid = $record->event->uuid;
        $this->forget($uuid);
        unset($this->cold[$uuid]);
        $this->cold[$uuid] = $record;
    }

    #[\Override]
    public function deleted(Record $record): void
    {
        $this->forget($record->event->uuid);
        unset($this->cold[$record->event->uuid]);
    }

    #[\Override]
    public function cleared(): void
    {
        $this->texts = [];
        $this->summaries = [];
        $this->cold = [];
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
     * @return int<0, max> The most bytes of the cached texts and summaries.
     */
    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    private static function isLarge(Record $record): bool
    {
        if ($record->frames === []) {
            try {
                return \strlen(Json::encode($record->event->payload)) > self::MAX_WARMED_BYTES;
            } catch (\JsonException) {
                return false;
            }
        }

        $size = 0;
        try {
            foreach ($record->frames as $frame) {
                $size += $frame->getSize();
                if ($size > self::MAX_WARMED_BYTES) {
                    return true;
                }
            }
        } catch (\Throwable) {
            // A frame that can't measure itself is left to on-demand rendering
            return true;
        }

        return false;
    }

    /**
     * @return array{int, string, int<0, max>, int<0, max>}|null
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
     * @return array{int, string, int<0, max>, int<0, max>} The entry, whether the cache has room for it or not.
     */
    private function keep(Record $record, string $text): array
    {
        $length = \mb_strlen($text);
        $kept = \strlen($text) <= $this->maxSearchedBytes
            ? $text
            : \mb_strcut($text, 0, $this->maxSearchedBytes, 'UTF-8');
        $entry = [$record->seq, $kept, $kept === $text ? $length : \mb_strlen($kept), $length];
        $size = \strlen($kept);
        if ($this->bytes + $size <= $this->maxBytes) {
            $this->texts[$record->event->uuid] = $entry;
            $this->bytes += $size;
        }

        return $entry;
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
