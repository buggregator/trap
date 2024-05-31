<?php

declare(strict_types=1);

namespace Buggregator\Trap\Config;

use Buggregator\Trap\Service\FilesObserver\FrameConverter;

/**
 * @internal
 */
final class FilesObserver
{
    /**
     * @param non-empty-string $path
     * @param class-string<FrameConverter> $converter
     * @param float $interval
     */
    public function __construct(
        public readonly string $path,
        public readonly string $converter,
        public readonly float $interval = 5.0,
    ) {}
}
