<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Html\Binary;

use Buggregator\Trap\Server\Event\Previewable;
use Buggregator\Trap\Server\Event\Searchable;
use Buggregator\Trap\Support\BinaryFormat;
use Buggregator\Trap\Support\HtmlText;
use Psr\Http\Message\StreamInterface;

/**
 * The `html` payload of a binary event: `{"title", "html", "size"}`, the size in bytes of the data.
 * The feed gets the card of the first bytes; the event page the viewer with up to {@see PAGE_BYTES} of them,
 * built when asked for, so the store keeps only the stream of the data. The search finds it by the text
 * of the card.
 *
 * @internal
 * @psalm-internal Buggregator\Trap\Module\Html
 */
final class Payload implements Previewable, Searchable
{
    /** Bytes the card shows: what fits its thumbnail. */
    public const CARD_BYTES = 256;

    /** Bytes the page of the event carries, in base64; the rest is downloaded. */
    public const PAGE_BYTES = 1024 * 1024;

    public const TITLE = 'Binary data';

    /** @var int<0, max> */
    public readonly int $size;

    public readonly ?BinaryFormat $format;

    /** @var non-empty-string */
    public readonly string $title;

    private readonly string $card;
    private readonly string $searchText;

    /**
     * @param non-empty-string $uuid Of the event.
     * @param StreamInterface $stream Seekable; read from the start, its position left as it was.
     */
    public function __construct(
        public readonly string $uuid,
        public readonly StreamInterface $stream,
        public readonly \DateTimeImmutable $time,
    ) {
        $this->size = \max(0, (int) $stream->getSize());
        $head = $this->bytes(BinaryFormat::HEAD);
        $this->format = BinaryFormat::guess($head);
        $this->title = self::TITLE . ($this->format === null ? '' : ': ' . $this->format->name);
        $this->card = Markup::card(\substr($head, 0, self::CARD_BYTES), $this->size, $this->format);
        $this->searchText = \mb_strtolower($this->title . "\n" . HtmlText::visible($this->card), 'UTF-8');
    }

    /**
     * The bytes from the start. Sync: no other reader moves the stream in between.
     *
     * @param int<0, max> $length The most bytes.
     */
    public function bytes(int $length): string
    {
        if ($length === 0) {
            return '';
        }

        $position = $this->stream->tell();
        try {
            $this->stream->seek(0);
            $bytes = '';
            while (\strlen($bytes) < $length && !$this->stream->eof()) {
                $chunk = $this->stream->read($length - \strlen($bytes));
                if ($chunk === '') {
                    break;
                }
                $bytes .= $chunk;
            }

            return $bytes;
        } finally {
            $this->stream->seek($position);
        }
    }

    /**
     * @return non-empty-string
     */
    public function fileName(): string
    {
        return \sprintf('binary-%s.%s', $this->time->format('Ymd-His'), $this->format->extension ?? 'bin');
    }

    /**
     * @return array{title: non-empty-string, html: string, size: int<0, max>}
     */
    #[\Override]
    public function preview(): array
    {
        return ['title' => $this->title, 'html' => $this->card, 'size' => $this->size];
    }

    /**
     * @return array{title: non-empty-string, html: string, size: int<0, max>}
     *
     * @throws \JsonException
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        $bytes = $this->bytes(self::PAGE_BYTES);
        $html = Markup::page(
            $bytes,
            $this->size,
            $this->format,
            $this->title,
            AssetsController::PATH . $this->uuid,
            \substr($bytes, 0, self::CARD_BYTES),
        );

        return ['title' => $this->title, 'html' => $html, 'size' => $this->size];
    }

    #[\Override]
    public function searchText(): string
    {
        return $this->searchText;
    }
}
