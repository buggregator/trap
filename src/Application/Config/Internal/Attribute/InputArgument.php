<?php

declare(strict_types=1);

namespace Buggregator\Trap\Application\Config\Internal\Attribute;

/**
 * Command line argument configuration attribute.
 *
 * Maps a property to a command line argument.
 *
 * @internal
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class InputArgument implements ConfigAttribute
{
    /**
     * @param non-empty-string $name Argument name
     */
    public function __construct(
        public string $name,
    ) {}
}
