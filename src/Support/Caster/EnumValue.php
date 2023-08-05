<?php

declare(strict_types=1);

namespace Buggregator\Trap\Support\Caster;

final class EnumValue
{
    public function __construct(
        public readonly string $class,
        public readonly string $name,
        public readonly int $value,
    ) {
    }
}

