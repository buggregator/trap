<?php

declare(strict_types=1);

namespace Buggregator\Trap\Config\Server\Files;

use Buggregator\Trap\Service\FilesObserver\FrameConverter;

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
