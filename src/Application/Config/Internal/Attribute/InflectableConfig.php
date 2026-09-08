<?php

declare(strict_types=1);

namespace Buggregator\Trap\Application\Config\Internal\Attribute;

/**
 * Indicates that the configuration class can be inflected from input, env, config files, or other sources.
 *
 * @internal
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class InflectableConfig implements ConfigAttribute {}
