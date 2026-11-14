<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\FilesObserver;

use Buggregator\Trap\Module\Profiler\Compaction\Report;
use Buggregator\Trap\Module\Profiler\Frame as ProfilerFrame;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Module\Profiler\XDebug\ProfileBuilder;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Server\Service\FilesObserver\FileInfo;
use Buggregator\Trap\Server\Service\FilesObserver\ForgetsFiles;
use Buggregator\Trap\Server\Service\FilesObserver\FrameConverter as FileFilterInterface;

/**
 * Converts Xdebug profiler files, gzipped or not.
 *
 * @internal
 */
final class XDebug implements FileFilterInterface, ForgetsFiles
{
    /** Enough for the `summary:` line and the blank line after it */
    private const TAIL_SIZE = 256;

    private readonly GzipFollower $gzip;

    public function __construct(
        private readonly Logger $logger,
        private readonly ProfileBuilder $profileBuilder,
    ) {
        $this->gzip = new GzipFollower(self::TAIL_SIZE);
    }

    /**
     * Recognizes a profile by the default `xdebug.profiler_output_name`, `cachegrind.out.%p`.
     * Not by the content: every file of the directory is checked on each scan.
     */
    #[\Override]
    public function validate(FileInfo $file): bool
    {
        return \str_starts_with(\basename($file->path), 'cachegrind.out.');
    }

    /**
     * Xdebug creates the file when profiling starts, adds a block as each call ends,
     * and writes the `summary:` line last. A file over the size limit is ready at once, unread: the conversion
     * skips it.
     */
    #[\Override]
    public function isReady(FileInfo $file): bool
    {
        if ($file->size > $this->profileBuilder->maxSize) {
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
                return $tail === false || ($tail !== null && self::hasSummary($tail));
            }

            \fseek($handle, -self::TAIL_SIZE, \SEEK_END) === 0 or \rewind($handle);
            return self::hasSummary((string) \stream_get_contents($handle));
        } finally {
            \fclose($handle);
        }
    }

    /**
     * @return \Traversable<int, ProfilerFrame>
     */
    #[\Override]
    public function convert(FileInfo $file): \Traversable
    {
        if ($file->size > $this->profileBuilder->maxSize) {
            $this->logger->warning(
                'XDebug: %s is skipped: it is larger than %d bytes.',
                \basename($file->path),
                $this->profileBuilder->maxSize,
            );
            return;
        }

        try {
            yield new ProfilerFrame(
                ProfilerFrame\Payload::new(
                    type: ProfilerFrame\Type::XDebug,
                    // Built when the profile is first shown, in whatever fiber that is: it can't wait for a lock.
                    // The node budget it gets is what the memory left affords.
                    callsProvider: function () use ($file): Profile {
                        // A byte over the limit is enough for the builder to reject a file that doesn't fit in memory
                        $content = \file_get_contents($file->path, length: $this->profileBuilder->maxSize + 1);
                        $content === false and throw new \RuntimeException('Unable to read the profile file.');

                        $profile = $this->profileBuilder->createProfile(
                            content: $content,
                            date: new \DateTimeImmutable('@' . $file->mtime),
                            metadata: [
                                'hostname' => (string) \gethostname(),
                                'filename' => \basename($file->path),
                                'filesize' => $file->size,
                            ],
                        );

                        /** @var mixed $compacted */
                        $compacted = $profile->metadata['compacted'] ?? null;
                        \is_array($compacted) and $this->logger->warning(
                            'XDebug: %s is compacted to %d call tree nodes: %s.',
                            \basename($file->path),
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

    #[\Override]
    public function forget(string $path): void
    {
        $this->gzip->forget($path);
    }

    private static function hasSummary(string $tail): bool
    {
        return \preg_match('/(?:^|\n)summary: [^\n]*\n\s*$/', $tail) === 1;
    }
}
