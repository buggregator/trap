<?php

declare(strict_types=1);

namespace Buggregator\Client\Support;

use Fiber;
use Psr\Http\Message\StreamInterface;

final class StreamHelper
{
    private const CHUNK_SIZE = 1048576; // 1 Mb
    private const WRITE_STREAM_CHUNK_SIZE = 8388608; // 8 Mb

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
            Fiber::suspend();
        }

        $stream->seek($caret, \SEEK_SET);
        return $result;
    }

    /**
     *
     * Write bytes from $from (current position) to $to (current position) until $boundary by chunks
     *
     * @param non-empty-string $boundary
     *
     * @return int Bytes written
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
                Fiber::suspend();
                break;
            }

            $result += \strlen($read);
            $to->write($read);

            unset($read);
            Fiber::suspend();
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
            Fiber::suspend();
        }
    }
}
