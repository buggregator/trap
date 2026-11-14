<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\FilesObserver;

/**
 * Follows gzipped files being written, to tell when their stream ends.
 *
 * Inflates only the bytes added since the last check: the end of a compressed stream can't be read on its own.
 *
 * @internal
 */
final class GzipFollower
{
    /** Bytes compared to tell a file being written from a file written anew at the same path */
    private const MARK_SIZE = 64;

    /** Compressed bytes inflated at a time: a crafted stream expands about a thousand times. */
    private const CHUNK = 8192;

    /** @var array<string, array{\InflateContext, int<0, max>, string, string}> Files being written: inflater, bytes read, the last of them, end of the inflated data */
    private array $inflating = [];

    /**
     * @param int<0, max> $tailSize Bytes of the inflated data to return.
     * @param positive-int $maxRead Compressed bytes inflated per call at most, so that a call never takes long:
     *        a large file is read over several calls.
     */
    public function __construct(
        private readonly int $tailSize = 0,
        private readonly int $maxRead = 32 * 1024 * 1024,
    ) {}

    /**
     * Releases the inflater of a file no longer followed.
     */
    public function forget(string $path): void
    {
        unset($this->inflating[$path]);
    }

    /**
     * @return int<0, max> Files being followed, for tests.
     */
    public function count(): int
    {
        return \count($this->inflating);
    }

    /**
     * @param non-empty-string $path
     * @param resource $handle The file opened for reading.
     *
     * @return string|false|null The end of the inflated data when the stream has ended; null while it hasn't,
     *         or it is not read to its end yet; false when the data is corrupted, which the conversion reports.
     */
    public function follow(string $path, $handle): string|false|null
    {
        $state = $this->inflating[$path] ?? null;
        if ($state === null || !self::endsWith($handle, $state[1], $state[2])) {
            $context = \inflate_init(\ZLIB_ENCODING_GZIP);
            $context === false and throw new \RuntimeException('Unable to initialize inflating.');
            $state = [$context, 0, '', ''];
        }

        [$context, $offset, $mark, $tail] = $state;
        \fseek($handle, $offset);
        $limit = $offset + $this->maxRead;
        while ($offset < $limit && \inflate_get_status($context) !== \ZLIB_STREAM_END
            && ($chunk = \fread($handle, \min(self::CHUNK, $limit - $offset))) !== false && $chunk !== ''
        ) {
            $offset += \strlen($chunk);
            $mark = \substr($mark . $chunk, -self::MARK_SIZE);
            $data = @\inflate_add($context, $chunk, \ZLIB_SYNC_FLUSH);
            if ($data === false) {
                unset($this->inflating[$path]);
                return false;
            }
            $this->tailSize === 0 or $tail = \substr($tail . $data, -$this->tailSize);
        }

        if (\inflate_get_status($context) !== \ZLIB_STREAM_END) {
            $this->inflating[$path] = [$context, $offset, $mark, $tail];
            return null;
        }

        unset($this->inflating[$path]);
        return $tail;
    }

    /**
     * Whether the bytes read before are still where they were: a file written anew differs there.
     *
     * @param resource $handle
     */
    private static function endsWith($handle, int $offset, string $mark): bool
    {
        return $mark !== ''
            && \fseek($handle, $offset - \strlen($mark)) === 0
            && \fread($handle, \strlen($mark)) === $mark;
    }
}
