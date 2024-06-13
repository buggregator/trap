<?php

declare(strict_types=1);

namespace Buggregator\Trap\Support;

use Http\Message\Encoding\GzipDecodeStream;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;

/**
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class StreamHelper
{
    private const CHUNK_SIZE = 1 * 1024 * 1024; // 1 Mb

    private const WRITE_STREAM_CHUNK_SIZE = 8 * 1024 * 1024; // 8 Mb

    private const MAX_FILE_MEMORY_SIZE = 4 * 1024 * 1024; // 4MB

    /**
     * @param non-empty-string $substr
     *
     * @return int<0, max>|false Relative position from current caret position
     */
    public static function strpos(StreamInterface $stream, string $substr): int|false
    {
        $caret = $stream->tell();
        $ssLen = \strlen($substr);
        \assert($ssLen > 0);
        \assert($stream->isSeekable());

        $delta = 0;
        $result = false;
        $prefix = '';
        while (!$stream->eof()) {
            $read = $prefix . $stream->read(self::CHUNK_SIZE + $ssLen);

            $readLen = \strlen($read);
            if ($readLen < $ssLen) {
                break;
            }

            if (false !== ($pos = \strpos($read, $substr))) {
                $result = $delta + $pos;
                break;
            }

            $prefix = \substr($read, - $ssLen);
            $delta += \strlen($read) - $ssLen;

            unset($read);
            \Fiber::suspend();
        }

        $stream->seek($caret, \SEEK_SET);

        \assert($result >= 0);
        return $result;
    }

    /**
     *
     * Write bytes from $from (current position) to $to (current position) until $boundary by chunks
     *
     * @param non-empty-string $boundary
     *
     * @return int<0, max> Bytes written
     */
    public static function writeStreamUntil(StreamInterface $from, StreamInterface $to, string $boundary): int
    {
        $result = 0;
        while (!$from->eof()) {
            $read = $from->read(self::WRITE_STREAM_CHUNK_SIZE);
            if (false !== ($pos = \strpos($read, $boundary))) {
                $from->seek($pos - \strlen($read), \SEEK_CUR);
                $read = \substr($read, 0, $pos);

                $result += \strlen($read);
                $to->write($read);
                unset($read);
                \Fiber::suspend();
                break;
            }

            $result += \strlen($read);
            $to->write($read);

            unset($read);
            \Fiber::suspend();
        }

        return $result;
    }

    /**
     * Write $bytes from $from (current position) to $to (current position) by chunks
     *
     * @param positive-int $bytes
     */
    public static function writeStream(StreamInterface $from, StreamInterface $to, int $bytes): void
    {
        $written = 0;
        while (!$from->eof() && $written < $bytes) {
            $diff = $bytes - $written;
            $toRead = $diff > self::WRITE_STREAM_CHUNK_SIZE && (self::WRITE_STREAM_CHUNK_SIZE * 1.2 > $diff)
                ? $diff
                : \min($diff, self::WRITE_STREAM_CHUNK_SIZE);
            $read = $from->read($toRead);
            $to->write($read);
            $written += \strlen($read);

            unset($read);
            \Fiber::suspend();
        }
    }

    public static function unzipBody(ServerRequestInterface $request): ServerRequestInterface
    {
        $gzippedStream = new GzipDecodeStream($request->getBody());

        $stream = self::createFileStream();
        StreamHelper::writeStream($gzippedStream, $stream, \PHP_INT_MAX);

        return $request->withBody($stream);
    }

    /**
     * @param array<non-empty-string|int, mixed> $readFilters filter name => filter options
     * @param array<non-empty-string|int, mixed> $writeFilters filter name => filter options
     *
     * @psalm-suppress UnusedFunctionCall
     */
    public static function createFileStream(array $readFilters = [], array $writeFilters = []): StreamInterface
    {
        $stream = \fopen('php://temp/maxmemory:' . self::MAX_FILE_MEMORY_SIZE, 'w+b');

        /** @var mixed $options */
        foreach ($readFilters as $filter => $options) {
            \is_string($filter)
                ? \stream_filter_append($stream, $filter, \STREAM_FILTER_READ, $options)
                : \stream_filter_append($stream, (string) $options, \STREAM_FILTER_READ);
        }

        /** @var mixed $options */
        foreach ($writeFilters as $filter => $options) {
            \is_string($filter)
                ? \stream_filter_append($stream, $filter, \STREAM_FILTER_WRITE, $options)
                : \stream_filter_append($stream, (string) $options, \STREAM_FILTER_WRITE);
        }

        return Stream::create($stream);
    }

    /**
     * Wrap a {@see StreamInterface} to read it concurrently.
     * The new stream will tell and seek the origin stream automatically to read it sequentially.
     *
     * TODO: implement
     *
     * @throws \InvalidArgumentException If the stream is not seekable.
     */
    public static function concurrentReadStream(StreamInterface $stream): StreamInterface
    {
        if (!$stream->isSeekable()) {
            throw new \InvalidArgumentException('Cannot read concurrently a non seekable stream.');
        }

        // TODO: implement
        // The idea is to create a new stream class that will store the current read position and seek the origin stream
        // before reading the next chunk.
        return $stream;
    }
}
