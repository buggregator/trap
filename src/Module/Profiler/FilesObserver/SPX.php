<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\FilesObserver;

use Buggregator\Trap\Module\Profiler\Compaction\Report;
use Buggregator\Trap\Module\Profiler\Frame as ProfilerFrame;
use Buggregator\Trap\Module\Profiler\SPX\ProfileBuilder;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Server\Service\FilesObserver\FileInfo;
use Buggregator\Trap\Server\Service\FilesObserver\ForgetsFiles;
use Buggregator\Trap\Server\Service\FilesObserver\FrameConverter;
use Buggregator\Trap\Support\Spx\Metadata;
use Buggregator\Trap\Support\Spx\TraceReader;

/**
 * Converts SPX full reports: a `spx-full-*.txt.gz` trace with its `.json` metadata next to it.
 *
 * SPX writes the metadata when profiling ends, then closes the gzip stream of the trace: a report is complete
 * when the stream has ended. A report whose metadata is missing or invalid then is skipped with a warning.
 * A report older than the observer is complete when its metadata is there: it is not inflated to tell.
 *
 * Reading the traces takes ext-zlib; without it, no report is taken, and that is logged once.
 *
 * @internal
 */
final class SPX implements FrameConverter, ForgetsFiles
{
    private const PREFIX = 'spx-full-';
    private const TRACE = '.txt.gz';

    private readonly GzipFollower $gzip;
    private readonly int $startedAt;
    private readonly bool $zlib;

    /**
     * @param bool|null $zlib Whether ext-zlib is there; by default, whether it is loaded.
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly ProfileBuilder $profileBuilder,
        ?bool $zlib = null,
    ) {
        $this->gzip = new GzipFollower();
        $this->startedAt = \time();
        $this->zlib = $zlib ?? \function_exists('inflate_init');
        $this->zlib or $logger->warning('SPX: the PHP of Trap has no ext-zlib, so SPX reports are not read.');
    }

    #[\Override]
    public function validate(FileInfo $file): bool
    {
        $name = \basename($file->path);

        return $this->zlib && \str_starts_with($name, self::PREFIX) && \str_ends_with($name, self::TRACE);
    }

    /**
     * A trace over the size limit is ready at once, unread: the conversion skips it.
     */
    #[\Override]
    public function isReady(FileInfo $file): bool
    {
        if ($file->size > $this->profileBuilder->maxSize
            || ($file->mtime < $this->startedAt && \is_file(self::metadataPath($file)))
        ) {
            return true;
        }

        $handle = @\fopen($file->path, 'rb');
        if ($handle === false) {
            return false;
        }
        try {
            // A corrupted stream is complete too: the conversion reports why
            return $this->gzip->follow($file->path, $handle) !== null;
        } finally {
            \fclose($handle);
        }
    }

    #[\Override]
    public function forget(string $path): void
    {
        $this->gzip->forget($path);
    }

    /**
     * The metadata is read at once, and a report is skipped when it is gone. The trace is read when the profile is
     * first shown, not kept open meanwhile: a file handle per profile not shown yet would run out, and on Windows
     * the file could not be moved or deleted.
     *
     * @return \Traversable<int, ProfilerFrame>
     */
    #[\Override]
    public function convert(FileInfo $file): \Traversable
    {
        $name = \basename($file->path);
        if ($file->size > $this->profileBuilder->maxSize) {
            $this->logger->warning(
                'SPX: %s is skipped: the trace is larger than %d bytes.',
                $name,
                $this->profileBuilder->maxSize,
            );
            return;
        }

        try {
            $metadata = self::metadata($file);
        } catch (\Throwable $e) {
            \is_file($file->path)
                ? $this->logger->warning('SPX: %s is skipped: %s', $name, $e->getMessage())
                : $this->logger->debug('SPX: %s is removed before it is read, skipped.', $name);
            return;
        }

        try {
            yield new ProfilerFrame(
                ProfilerFrame\Payload::new(
                    type: ProfilerFrame\Type::SPX,
                    // Built when the profile is first shown, in whatever fiber that is: it can't wait for a lock.
                    // The node budget it gets is what the memory left affords.
                    callsProvider: function () use ($file, $metadata, $name): Profile {
                        $handle = @\fopen($file->path, 'rb') ?: throw new \RuntimeException(
                            "The SPX report $name is removed before it is shown.",
                        );
                        try {
                            $profile = $this->profileBuilder->createProfile(
                                $metadata,
                                TraceReader::chunks($handle),
                                metadata: [
                                    'filename' => $name,
                                    'filesize' => $file->size,
                                ],
                            );
                        } finally {
                            \fclose($handle);
                        }

                        /** @var mixed $compacted */
                        $compacted = $profile->metadata['compacted'] ?? null;
                        \is_array($compacted) and $this->logger->warning(
                            'SPX: %s is compacted to %d call tree nodes: %s.',
                            $name,
                            (int) ($compacted['limit'] ?? 0),
                            Report::summarize($compacted),
                        );

                        return $profile;
                    },
                ),
            );
        } catch (\Throwable $e) {
            $this->logger->exception($e);
        }
    }

    private static function metadataPath(FileInfo $file): string
    {
        return \substr($file->path, 0, -\strlen(self::TRACE)) . '.json';
    }

    private static function metadata(FileInfo $file): Metadata
    {
        $path = self::metadataPath($file);
        $json = @\file_get_contents($path, length: Metadata::MAX_SIZE + 1);
        $json === false and throw new \RuntimeException("Unable to read the report metadata `$path`.");

        return Metadata::fromJson($json);
    }
}
