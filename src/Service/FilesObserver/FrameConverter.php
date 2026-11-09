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
     * Whether the file is written completely and can be converted.
     * Called again for a file that wasn't ready, each time its size or modification time changes.
     */
    public function isReady(FileInfo $file): bool;

    /**
     * @return iterable<int, ProfilerFrame>
     */
    public function convert(FileInfo $file): iterable;
}
