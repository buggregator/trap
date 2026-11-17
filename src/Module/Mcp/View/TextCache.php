<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp\View;

use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Server\Event\Record;
use Buggregator\Trap\Server\Event\StoreListener;

/**
 * The text views of the stored events, rendered once for the searches. An entry lives while its record
 * is the stored one, and the oldest entries go when the texts outgrow {@see self::MAX_BYTES}.
 *
 * @internal
 */
final class TextCache implements StoreListener
{
    public const MAX_BYTES = 16 * 1024 * 1024;

    /** @var array<string, array{int, string}> Event UUID => sequence number of its record and text, oldest first */
    private array $texts = [];

    /** @var int<0, max> */
    private int $bytes = 0;

    public function __construct(
        private readonly EventViews $views,
        EventStore $store,
    ) {
        $store->listen($this);
    }

    public function text(Record $record): string
    {
        $uuid = $record->event->uuid;
        $cached = $this->texts[$uuid] ?? null;
        if ($cached !== null && $cached[0] === $record->seq) {
            return $cached[1];
        }

        $this->forget($uuid);
        $text = $this->views->text($record);
        $size = \strlen($text);
        if ($size > self::MAX_BYTES) {
            return $text;
        }

        while ($this->bytes + $size > self::MAX_BYTES && $this->texts !== []) {
            $this->forget(\array_key_first($this->texts));
        }

        $this->texts[$uuid] = [$record->seq, $text];
        $this->bytes += $size;

        return $text;
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
        $this->bytes = 0;
    }

    /**
     * @return int<0, max> The number of cached texts.
     */
    public function count(): int
    {
        return \count($this->texts);
    }

    private function forget(string $uuid): void
    {
        $cached = $this->texts[$uuid] ?? null;
        if ($cached === null) {
            return;
        }

        unset($this->texts[$uuid]);
        $this->bytes = \max(0, $this->bytes - \strlen($cached[1]));
    }
}
