<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Traffic\Parser;

use Buggregator\Trap\Support\Stream\SizeLimitExceeded;
use Psr\Http\Message\StreamInterface;

/**
 * Decodes the `chunked` transfer coding of a request body fed to it in arbitrary pieces,
 * and writes the payload to a stream. Chunk extensions and trailer fields are skipped.
 *
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class ChunkedDecoder
{
    /** Longest chunk-size line or trailer field, in bytes */
    private const MAX_LINE_LENGTH = 8192;

    /** Longest trailer section, in bytes */
    private const MAX_TRAILER_SIZE = 65536;

    private const STATE_SIZE = 0;
    private const STATE_DATA = 1;
    private const STATE_DATA_END = 2;
    private const STATE_TRAILER = 3;
    private const STATE_DONE = 4;

    private int $state = self::STATE_SIZE;

    /** An unfinished line: it continues in the next piece */
    private string $line = '';

    /** Bytes of the current chunk not read yet */
    private int $chunkLeft = 0;

    /** @var int<0, max> */
    private int $size = 0;

    /** @var int<0, max> */
    private int $trailerSize = 0;

    /**
     * @param int<0, max> $maxSize Limit of the decoded body, in bytes.
     */
    public function __construct(
        private readonly StreamInterface $to,
        private readonly int $maxSize,
    ) {}

    /**
     * @return bool Whether the body has ended; bytes of the piece past its end are ignored.
     *
     * @throws InvalidRequest When the framing is broken.
     * @throws SizeLimitExceeded When a chunk would grow the body past the limit; nothing of it is written.
     */
    public function write(string $data): bool
    {
        $offset = 0;
        $length = \strlen($data);
        while ($offset < $length && $this->state !== self::STATE_DONE) {
            if ($this->state === self::STATE_DATA) {
                $piece = \substr($data, $offset, $this->chunkLeft);
                $this->to->write($piece);
                $offset += \strlen($piece);
                $this->chunkLeft -= \strlen($piece);
                $this->chunkLeft === 0 and $this->state = self::STATE_DATA_END;
                continue;
            }

            $eol = \strpos($data, "\n", $offset);
            $end = $eol === false ? $length : $eol + 1;
            $this->line .= \substr($data, $offset, $end - $offset);
            $offset = $end;
            \strlen($this->line) > self::MAX_LINE_LENGTH and throw new InvalidRequest('Invalid chunked body.');

            if ($eol !== false) {
                // A bare LF ends a line too: some clients send it
                $line = \substr($this->line, 0, \str_ends_with($this->line, "\r\n") ? -2 : -1);
                $this->line = '';
                $this->readLine($line);
            }
        }

        return $this->state === self::STATE_DONE;
    }

    private function readLine(string $line): void
    {
        switch ($this->state) {
            case self::STATE_SIZE:
                $this->readChunkSize($line);
                return;
            case self::STATE_DATA_END:
                $line === '' or throw new InvalidRequest('Invalid chunked body.');
                $this->state = self::STATE_SIZE;
                return;
            case self::STATE_TRAILER:
                if ($line === '') {
                    $this->state = self::STATE_DONE;
                    return;
                }

                $this->trailerSize += \strlen($line);
                $this->trailerSize > self::MAX_TRAILER_SIZE and throw new InvalidRequest('Invalid chunked body.');
                return;
        }
    }

    private function readChunkSize(string $line): void
    {
        $hex = \trim(\explode(';', $line, 2)[0], " \t");
        \preg_match('/^[0-9a-fA-F]+$/', $hex) === 1 or throw new InvalidRequest('Invalid chunked body.');

        $chunkSize = \hexdec($hex);
        // A float is a size past the integer range
        if (!\is_int($chunkSize) || $chunkSize > $this->maxSize - $this->size) {
            throw new SizeLimitExceeded("The chunked request body is larger than {$this->maxSize} bytes.");
        }

        if ($chunkSize <= 0) {
            $this->state = self::STATE_TRAILER;
            return;
        }

        $this->size += $chunkSize;
        $this->chunkLeft = $chunkSize;
        $this->state = self::STATE_DATA;
    }
}
