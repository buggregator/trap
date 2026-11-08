<?php

declare(strict_types=1);

namespace Buggregator\Trap\Application\Config\Internal\Attribute;

/**
 * PHP INI configuration attribute.
 *
 * Maps a property to a PHP INI setting.
 *
 * @internal
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class PhpIni implements ConfigAttribute
{
    /**
     * @param non-empty-string $option PHP INI option name
     */
    public function __construct(
        public string $option,
    ) {}
}
