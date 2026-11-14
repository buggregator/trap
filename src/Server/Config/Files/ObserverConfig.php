<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Config\Files;

use Buggregator\Trap\Server\Service\FilesObserver\FrameConverter;

/**
 * @internal
 */
abstract class ObserverConfig
{
    /** @var non-empty-string|null */
    public ?string $path = null;

    /** @var class-string<FrameConverter>|null */
    public ?string $converterClass = null;

    /** @var float Scan interval in seconds */
    public float $scanInterval = 5.0;

    /**
     * Skip the files written completely before the observer started, for a directory that keeps old files,
     * so that they don't flood the feed on each start. The files still being written are followed.
     */
    public bool $skipOldFiles = false;

    /**
     * @var float|null Seconds a file being written may stay unchanged before it is dropped; null to follow it
     *      until it is complete or removed.
     */
    public ?float $abandonAfter = 600.0;

    /**
     * @psalm-assert-if-true non-empty-string $this->path
     * @psalm-assert-if-true class-string<FrameConverter> $this->converterClass
     */
    public function isValid(): bool
    {
        /** @psalm-suppress RedundantCondition */
        return $this->path !== null && $this->converterClass !== null && $this->path !== ''
            && \is_a($this->converterClass, FrameConverter::class, true) && $this->scanInterval > 0.0;
    }
}
