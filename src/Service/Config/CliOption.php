<?php

declare(strict_types=1);

namespace Buggregator\Trap\Service\Config;

/**
 * @internal
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class CliOption implements ConfigAttribute
{
    public function __construct(
        public string $name,
    ) {
    }
}
