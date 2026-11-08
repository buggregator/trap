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
                                'filename' => $file->getName(),
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
}
