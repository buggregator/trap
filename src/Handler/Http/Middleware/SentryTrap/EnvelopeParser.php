<?php

declare(strict_types=1);

namespace Buggregator\Trap\Handler\Http\Middleware\SentryTrap;

use Buggregator\Trap\Proto\Frame\Sentry\EnvelopeItem;
use Buggregator\Trap\Proto\Frame\Sentry\SentryEnvelope;
use Buggregator\Trap\Support\StreamHelper;
use Psr\Http\Message\StreamInterface;

/**
 * @internal
 * @psalm-internal Buggregator\Trap\Handler\Http\Middleware
 */
final class EnvelopeParser
{
    private const MAX_TEXT_ITEM_SIZE = 1024 * 1024; // 1MB

    private const MAX_BINARY_ITEM_SIZE = 100 * 1024 * 1024; // 100MB

    public static function parse(
        StreamInterface $stream,
        \DateTimeImmutable $time = new \DateTimeImmutable(),
    ): SentryEnvelope {
        // Parse headers
        $headers = \json_decode(self::readLine($stream), true, 4, JSON_THROW_ON_ERROR);

        // Parse items
        $items = [];
        do {
            try {
                $items[] = self::parseItem($stream);
            } catch (\Throwable) {
                break;
            }
        } while (true);

        return new SentryEnvelope($headers, $items, $time);
    }

    /**
     * @throws \Throwable
     */
    private static function parseItem(StreamInterface $stream): EnvelopeItem
    {
        // Parse item header
        $itemHeader = \json_decode(self::readLine($stream), true, 4, JSON_THROW_ON_ERROR);

        $length = isset($itemHeader['length']) ? (int) $itemHeader['length'] : null;
        $length >= 0 or throw new \RuntimeException('Invalid item length.');

        $type = $itemHeader['type'] ?? null;

        if ($length > ($type === 'attachment' ? self::MAX_BINARY_ITEM_SIZE : self::MAX_TEXT_ITEM_SIZE)) {
            throw new \RuntimeException('Item is too big.');
        }

        /** @var mixed $itemPayload */
        $itemPayload = match (true) {
            // Store attachments as a file stream
            $type === 'attachment' => $length === null
                ? StreamHelper::createFileStream()->write(self::readLine($stream))
                : StreamHelper::createFileStream()->write(self::readBytes($stream, $length)),

            // Text items
            default => $length === null
                ? \json_decode(self::readLine($stream), true, 512, JSON_THROW_ON_ERROR)
                : \json_decode(self::readBytes($stream, $length), true, 512, JSON_THROW_ON_ERROR),
        };

        return new EnvelopeItem($itemHeader, $itemPayload);
    }

    /**
     * @param positive-int $possibleBytes Maximum number of bytes to read. If the read fragment is longer than this
     *        an exception will be thrown. Default is 10MB
     * @throws \Throwable
     */
    private static function readLine(StreamInterface $stream, int $possibleBytes = self::MAX_TEXT_ITEM_SIZE): string
    {
        $currentPos = $stream->tell();
        $relOffset = StreamHelper::strpos($stream, "\n");
        $size = $stream->getSize();
        $offset = $relOffset === false ? $size : $currentPos + $relOffset;

        // Validate offset
        $offset === null and throw new \RuntimeException('Failed to detect line end.');
        $offset - $currentPos > $possibleBytes and throw new \RuntimeException('Line is too long.');
        $offset === $currentPos and throw new \RuntimeException('End of stream.');

        $result = self::readBytes($stream, $offset - $currentPos);
        $size === $offset or $stream->seek(1, \SEEK_CUR);

        return $result;
    }

    /**
     * @param int<0, max> $length
     * @throws \Throwable
     */
    private static function readBytes(StreamInterface $stream, int $length): string
    {
        if ($length === 0) {
            return '';
        }

        $currentPos = $stream->tell();
        $size = $stream->getSize();

        $size !== null && $size - $currentPos < $length and throw new \RuntimeException('Not enough bytes to read.');

        /** @var non-empty-string $result */
        $result = '';
        do {
            $read = $stream->read($length);
            $read === '' and throw new \RuntimeException('Failed to read bytes.');

            $result .= $read;
            $length -= \strlen($read);
            if ($length === 0) {
                break;
            }

            \Fiber::suspend();
        } while (true);

        return $result;
    }
}
