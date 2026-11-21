<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebugTrace\Trace;

use Buggregator\Trap\Support\TimeSlice;

/**
 * Reads the records of a trace in the computer-readable format (`xdebug.trace_format=1`), plain or gzipped,
 * one by one: a framework request makes 10^5–10^6 lines, so nothing keeps the trace whole.
 *
 *     Version: 3.4.5
 *     File format: 4
 *     TRACE START [2026-11-08 10:00:00.000001]
 *     ...records...
 *                 time  memory          footer
 *     TRACE END   [2026-11-08 10:00:00.100000]
 *
 * @internal
 */
final class TraceReader
{
    /**
     * A longer line is cut there. Xdebug caps values by `xdebug.var_display_max_*`, so real lines stay far below.
     */
    public const MAX_LINE = 1 << 20;

    public string $version = '';
    public int $fileFormat = 0;

    /** TRACE START timestamp */
    public string $start = '';

    /** TRACE END timestamp; empty when the trace was cut */
    public string $end = '';

    /** Footer: seconds at the end of the script */
    public float $endTime = 0.0;

    /** Footer: memory at the end of the script */
    public int $endMemory = 0;

    /** A line was longer than {@see MAX_LINE} and was cut. */
    public bool $cut = false;

    /** Uncompressed bytes consumed by the lines read so far */
    private int $pos = 0;

    /** Offset of the line of the record {@see next()} returned last */
    private int $recOff = 0;

    private int $records = 0;

    /**
     * @param resource $handle
     * @param int $limit Uncompressed bytes to read at most; 0 for no limit.
     */
    private function __construct(
        private $handle,
        private readonly bool $seekable,
        private readonly int $limit,
        private readonly ?TimeSlice $slice,
    ) {}

    /**
     * Gzip is told by its magic bytes.
     *
     * @param int $limit Uncompressed bytes to read at most; 0 for no limit. Past it {@see next()} throws.
     * @param TimeSlice|null $slice Ticked on each record, to let the other fibers run during a long read.
     *
     * @throws \RuntimeException When the file can't be opened.
     */
    public static function open(string $path, int $limit = 0, ?TimeSlice $slice = null): self
    {
        $gzip = self::isGzip($path);
        $handle = @\fopen($gzip ? 'compress.zlib://' . $path : $path, 'rb');
        $handle === false and throw new \RuntimeException("Can't open the trace file `$path`.");

        return new self($handle, !$gzip, $limit, $slice);
    }

    public static function isGzip(string $path): bool
    {
        $handle = @\fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            return \fread($handle, 2) === "\x1f\x8b";
        } finally {
            \fclose($handle);
        }
    }

    /**
     * The uncompressed byte offset where the line of the record {@see next()} returned last starts.
     */
    public function offset(): int
    {
        return $this->recOff;
    }

    /**
     * Moves forward to the uncompressed offset, the start of a record line: an {@see offset()} of an earlier
     * pass. The header is not read again, so the next record is taken as valid.
     *
     * @throws TraceError
     */
    public function skipTo(int $offset): void
    {
        $offset < $this->pos and throw TraceError::badOffset();
        $this->limit > 0 && $offset > $this->limit and throw TraceError::tooLarge($this->limit);

        if ($this->seekable) {
            $size = \fstat($this->handle)['size'] ?? 0;
            $offset >= $size || \fseek($this->handle, $offset) !== 0 and throw TraceError::badOffset();
            $this->pos = $offset;
        } else {
            while ($this->pos < $offset) {
                $chunk = @\fread($this->handle, \min(65536, $offset - $this->pos));
                $chunk === false || $chunk === '' and throw TraceError::badOffset();
                $this->pos += \strlen($chunk);
                $this->slice?->tick();
            }
            \feof($this->handle) and throw TraceError::badOffset();
        }

        $this->records === 0 and $this->records = 1;
    }

    /**
     * @return Record|null Null at the end of the trace.
     *
     * @throws TraceError When the limit is reached, or the first data line is not a format 1 record.
     */
    public function next(): ?Record
    {
        while (true) {
            $this->limit > 0 && $this->pos > $this->limit and throw TraceError::tooLarge($this->limit);
            $this->slice?->tick();

            $start = $this->pos;
            $line = $this->readLine();
            if ($line === null) {
                $this->records === 0 && $this->fileFormat === 0 and throw TraceError::notFormat1();
                return null;
            }

            if ($line === '' || $this->header($line)) {
                continue;
            }

            $record = Record::parse($line);
            if ($record === null) {
                // A stray line inside a valid trace is skipped
                $this->records === 0 and throw TraceError::notFormat1();
                continue;
            }

            ++$this->records;
            $this->recOff = $start;
            return $record;
        }
    }

    /**
     * Whether the reader went past the limit.
     */
    public function overLimit(): bool
    {
        return $this->limit > 0 && $this->pos > $this->limit;
    }

    public function __destruct()
    {
        \fclose($this->handle);
    }

    private static function bracket(string $line): string
    {
        $i = \strpos($line, '[');
        $j = \strrpos($line, ']');

        return $i === false || $j === false || $j <= $i ? '' : \substr($line, $i + 1, $j - $i - 1);
    }

    private function readLine(): ?string
    {
        $line = @\fgets($this->handle, self::MAX_LINE + 1);
        if ($line === false) {
            return null;
        }

        $this->pos += \strlen($line);
        if (!\str_ends_with($line, "\n") && !\feof($this->handle)) {
            // The rest of a line over the limit is dropped
            $this->cut = true;
            while (($rest = @\fgets($this->handle, 65536)) !== false) {
                $this->pos += \strlen($rest);
                if (\str_ends_with($rest, "\n")) {
                    break;
                }
            }
        }

        return \rtrim($line, "\r\n");
    }

    /**
     * Reads the non-record lines.
     */
    private function header(string $line): bool
    {
        switch (true) {
            case \str_starts_with($line, 'Version: '):
                $this->version = \trim(\substr($line, 9));
                return true;
            case \str_starts_with($line, 'File format: '):
                $this->fileFormat = (int) \trim(\substr($line, 13));
                return true;
            case \str_starts_with($line, 'TRACE START'):
                $this->start = self::bracket($line);
                return true;
            case \str_starts_with($line, 'TRACE END'):
                $this->end = self::bracket($line);
                return true;
            case \str_starts_with($line, "\t\t\t"):
                $f = \explode("\t", $line);
                if (\count($f) >= 5) {
                    $this->endTime = (float) $f[3];
                    $this->endMemory = (int) $f[4];
                }
                return true;
            default:
                return false;
        }
    }
}
