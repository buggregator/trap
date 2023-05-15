<?php

declare(strict_types=1);

namespace Buggregator\Client\Traffic\Http;

use Psr\Http\Message\StreamInterface;

final class StreamHelper
{
    private const CHUNK_SIZE = 4096;

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

            $prefix = \substr($read, -$ssLen);
            $delta += \strlen($read) - $ssLen;
        }

        $stream->seek($caret, \SEEK_SET);
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
            $toRead = $diff > self::CHUNK_SIZE && (self::CHUNK_SIZE * 1.2 > $diff)
                ? $diff
                : \min($diff, self::CHUNK_SIZE);
            $read = $from->read($toRead);
            $to->write($read);
            $written += \strlen($read);
        }
    }
}
