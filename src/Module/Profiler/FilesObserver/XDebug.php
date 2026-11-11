<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\FilesObserver;

use Buggregator\Trap\Module\Profiler\Compaction\Report;
use Buggregator\Trap\Module\Profiler\Frame as ProfilerFrame;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Module\Profiler\XDebug\ProfileBuilder;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Server\Service\FilesObserver\FileInfo;
use Buggregator\Trap\Server\Service\FilesObserver\FrameConverter as FileFilterInterface;

/**
 * Converts Xdebug profiler files, gzipped or not.
 *
 * @internal
 */
final class XDebug implements FileFilterInterface
{
    /** Enough for the `summary:` line and the blank line after it */
    private const TAIL_SIZE = 256;

    /** Bytes compared to tell a file being written from a file written anew at the same path */
    private const MARK_SIZE = 64;

    /** @var array<string, array{\InflateContext, int<0, max>, string, string}> Gzipped files being written: inflater, bytes read, the last of them, end of the inflated data */
    private array $inflating = [];

    public function __construct(
        private readonly Logger $logger,
        private readonly ProfileBuilder $profileBuilder,
    ) {}

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
     * and writes the `summary:` line last.
     */
    #[\Override]
    public function isReady(FileInfo $file): bool
    {
        $handle = @\fopen($file->path, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            if (\fread($handle, 2) === "\x1f\x8b") {
                return $this->isGzipReady($file, $handle);
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

    private static function hasSummary(string $tail): bool
    {
        return \preg_match('/(?:^|\n)summary: [^\n]*\n\s*$/', $tail) === 1;
    }

    /**
     * Inflates only the bytes added since the last check: the end of a compressed stream can't be read on its own.
     *
     * @param resource $handle
     */
    private function isGzipReady(FileInfo $file, $handle): bool
    {
        $state = $this->inflating[$file->path] ?? null;
        if ($state === null || !self::endsWith($handle, $state[1], $state[2])) {
            $context = \inflate_init(\ZLIB_ENCODING_GZIP);
            $context === false and throw new \RuntimeException('Unable to initialize inflating.');
            $state = [$context, 0, '', ''];
        }

        [$context, $offset, $mark, $tail] = $state;
        \fseek($handle, $offset);
        while (\inflate_get_status($context) !== \ZLIB_STREAM_END
            && ($chunk = \fread($handle, 65536)) !== false && $chunk !== ''
        ) {
            $offset += \strlen($chunk);
            $mark = \substr($mark . $chunk, -self::MARK_SIZE);
            $data = @\inflate_add($context, $chunk, \ZLIB_SYNC_FLUSH);
            if ($data === false) {
                // Corrupted: the conversion reports why
                unset($this->inflating[$file->path]);
                return true;
            }
            $tail = \substr($tail . $data, -self::TAIL_SIZE);
        }

        if (\inflate_get_status($context) !== \ZLIB_STREAM_END) {
            $this->inflating[$file->path] = [$context, $offset, $mark, $tail];
            return false;
        }

        unset($this->inflating[$file->path]);
        return self::hasSummary($tail);
    }
}
