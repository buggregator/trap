<?php

declare(strict_types=1);

namespace Buggregator\Trap\Service\FilesObserver\Converter;

use Buggregator\Trap\Logger;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Module\Profiler\XDebug\ProfileBuilder;
use Buggregator\Trap\Proto\Frame\Profiler as ProfilerFrame;
use Buggregator\Trap\Service\FilesObserver\FileInfo;
use Buggregator\Trap\Service\FilesObserver\FrameConverter as FileFilterInterface;

/**
 * Converts Xdebug profiler files, gzipped or not.
 *
 * @internal
 */
final class XDebug implements FileFilterInterface
{
    /** Enough for the `summary:` line and the blank line after it */
    private const TAIL_SIZE = 256;

    /** @var array<string, array{\InflateContext, int<0, max>, string}> Gzipped files being written: inflater, bytes read, end of the inflated data */
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
                    callsProvider: function () use ($file): Profile {
                        $content = \file_get_contents($file->path);
                        $content === false and throw new \RuntimeException('Unable to read the profile file.');

                        return $this->profileBuilder->createProfile(
                            content: $content,
                            date: new \DateTimeImmutable('@' . $file->mtime),
                            metadata: [
                                'hostname' => (string) \gethostname(),
                                'filename' => \basename($file->path),
                                'filesize' => $file->size,
                            ],
                        );
                    },
                ),
            );
        } catch (\Throwable $e) {
            $this->logger->exception($e);
        }
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
        // A file shorter than the bytes already read was written anew
        if ($state === null || $state[1] > $file->size) {
            $context = \inflate_init(\ZLIB_ENCODING_GZIP);
            $context === false and throw new \RuntimeException('Unable to initialize inflating.');
            $state = [$context, 0, ''];
        }

        [$context, $offset, $tail] = $state;
        \fseek($handle, $offset);
        while (\inflate_get_status($context) !== \ZLIB_STREAM_END
            && ($chunk = \fread($handle, 65536)) !== false && $chunk !== ''
        ) {
            $offset += \strlen($chunk);
            $data = @\inflate_add($context, $chunk, \ZLIB_SYNC_FLUSH);
            if ($data === false) {
                // Corrupted: the conversion reports why
                unset($this->inflating[$file->path]);
                return true;
            }
            $tail = \substr($tail . $data, -self::TAIL_SIZE);
        }

        if (\inflate_get_status($context) !== \ZLIB_STREAM_END) {
            $this->inflating[$file->path] = [$context, $offset, $tail];
            return false;
        }

        unset($this->inflating[$file->path]);
        return self::hasSummary($tail);
    }
}
