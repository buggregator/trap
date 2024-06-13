<?php

declare(strict_types=1);

namespace Buggregator\Trap\Service\FilesObserver\Converter;

use Buggregator\Trap\Logger;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Module\Profiler\XHProf\ProfileBuilder;
use Buggregator\Trap\Proto\Frame\Profiler as ProfilerFrame;
use Buggregator\Trap\Service\FilesObserver\FileInfo;
use Buggregator\Trap\Service\FilesObserver\FrameConverter as FileFilterInterface;

/**
 * @psalm-type RawData = array<non-empty-string, array{
 *      ct: int<0, max>,
 *      wt: int<0, max>,
 *      cpu: int<0, max>,
 *      mu: int<0, max>,
 *      pmu: int<0, max>
 *  }>
 *
 * @internal
 */
final class XHProf implements FileFilterInterface
{
    public function __construct(
        private readonly Logger $logger,
        private readonly ProfileBuilder $profileBuilder,
    ) {}

    public function validate(FileInfo $file): bool
    {
        return $file->getExtension() === 'xhprof';
    }

    /**
     * @return \Traversable<int, ProfilerFrame>
     */
    public function convert(FileInfo $file): \Traversable
    {
        try {
            yield new ProfilerFrame(
                ProfilerFrame\Payload::new(
                    type: ProfilerFrame\Type::XHProf,
                    callsProvider: function () use ($file): Profile {
                        $content = \file_get_contents($file->path);
                        /** @var RawData $data */
                        $data = \unserialize($content, ['allowed_classes' => false]);
                        return $this->profileBuilder->createProfile(
                            date: new \DateTimeImmutable('@' . $file->mtime),
                            metadata: [
                                'hostname' => \gethostname(),
                                'filename' => $file->getName(),
                                'filesize' => $file->size,
                            ],
                            calls: $data,
                        );
                    },
                ),
            );
        } catch (\Throwable $e) {
            $this->logger->exception($e);
        }
    }
}
