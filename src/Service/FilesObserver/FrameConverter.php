<?php

declare(strict_types=1);

namespace Buggregator\Trap\Service\FilesObserver;

use Buggregator\Trap\Proto\Frame\Profiler as ProfilerFrame;

/**
 * Converts file to {@see ProfilerFrame}.
 *
 * @internal
 */
interface FrameConverter
{
    /**
     * Validate file.
     */
    public function validate(FileInfo $file): bool;

    /**
     * @return iterable<int, ProfilerFrame>
     */
    public function convert(FileInfo $file): iterable;
}
