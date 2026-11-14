<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Service\FilesObserver\Stub;

use Buggregator\Trap\Server\Config\Files\ObserverConfig;
use Buggregator\Trap\Server\Service\FilesObserver\FrameConverter;

final class FakeConfig extends ObserverConfig
{
    /** @var class-string<FrameConverter>|null */
    public ?string $converterClass = FakeConverter::class;
}
