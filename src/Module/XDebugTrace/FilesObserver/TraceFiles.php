<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebugTrace\FilesObserver;

use Buggregator\Trap\Module\XDebugTrace\Config\Traces;
use Buggregator\Trap\Module\XDebugTrace\Frame;
use Buggregator\Trap\Module\XDebugTrace\TraceStorage;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Server\Service\FilesObserver\FileInfo;
use Buggregator\Trap\Server\Service\FilesObserver\ForgetsFiles;
use Buggregator\Trap\Server\Service\FilesObserver\FrameConverter;
use Buggregator\Trap\Server\Service\FilesObserver\GzipFollower;
use Buggregator\Trap\Support\TimeSlice;

/**
 * Copies Xdebug trace files, gzipped or not, into the {@see TraceStorage}. The file stays where it is.
 *
 * @internal
 */
final class TraceFiles implements FrameConverter, ForgetsFiles
{
    /** Enough for the footer and the `TRACE END` line */
    private const TAIL_SIZE = 256;

    private readonly GzipFollower $gzip;

    public function __construct(
        private readonly Logger $logger,
        private readonly TraceStorage $storage,
        private readonly Traces $config,
    ) {
        $this->gzip = new GzipFollower(self::TAIL_SIZE);
    }

    /**
     * Recognizes a trace by the extension Xdebug gives it, whatever `xdebug.trace_output_name` is.
     */
    #[\Override]
    public function validate(FileInfo $file): bool
    {
        return \str_ends_with($file->path, '.xt') || \str_ends_with($file->path, '.xt.gz');
    }

    /**
     * Xdebug writes `TRACE END` last. A file over the size limit is ready at once, unread: the conversion
     * skips it.
     */
    #[\Override]
    public function isReady(FileInfo $file): bool
    {
        if ($file->size > $this->config->maxUploadBytes()) {
            return true;
        }

        $handle = @\fopen($file->path, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            if (\fread($handle, 2) === "\x1f\x8b") {
                $tail = $this->gzip->follow($file->path, $handle);
                // Corrupted: the conversion reports why
                return $tail === false || ($tail !== null && self::hasEnd($tail));
            }

            \fseek($handle, -self::TAIL_SIZE, \SEEK_END) === 0 or \rewind($handle);
            return self::hasEnd((string) \stream_get_contents($handle));
        } finally {
            \fclose($handle);
        }
    }

    /**
     * @return \Traversable<int, Frame>
     */
    #[\Override]
    public function convert(FileInfo $file): \Traversable
    {
        $name = \basename($file->path);
        if ($file->size > $this->config->maxUploadBytes()) {
            $this->logger->warning(
                'Xdebug trace %s is skipped: it is larger than %d bytes.',
                $name,
                $this->config->maxUploadBytes(),
            );
            return;
        }

        $handle = @\fopen($file->path, 'rb');
        if ($handle === false) {
            $this->logger->warning('Xdebug trace %s is skipped: it can\'t be read.', $name);
            return;
        }

        try {
            $trace = $this->storage->store($handle, new TimeSlice());
        } catch (\Throwable $e) {
            $this->logger->warning('Xdebug trace %s is skipped: %s', $name, $e->getMessage());
            return;
        } finally {
            \fclose($handle);
        }

        yield Frame::fromStored($trace, name: $name, time: new \DateTimeImmutable('@' . $file->mtime));
    }

    #[\Override]
    public function forget(string $path): void
    {
        $this->gzip->forget($path);
    }

    private static function hasEnd(string $tail): bool
    {
        return \preg_match('/(?:^|\n)TRACE END\s+\[[^\n]*\]\s*$/', $tail) === 1;
    }
}
