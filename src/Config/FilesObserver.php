<?php

declare(strict_types=1);

namespace Buggregator\Trap\Config;

final class FilesObserver
{
    public function __construct(
        public readonly string $path,
        public readonly float $interval = 5.0,
    ) {
    }
}
