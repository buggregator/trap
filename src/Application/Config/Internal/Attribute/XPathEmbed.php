<?php

declare(strict_types=1);

namespace Buggregator\Trap\Application\Config\Internal\Attribute;

/**
 * XPath embedding configuration attribute.
 *
 * Maps a property to a single object by loading it from an XML element
 * that matches the XPath expression.
 *
 * @internal
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class XPathEmbed implements ConfigAttribute
{
    /**
     * @param non-empty-string $path XPath expression to locate element
     * @param class-string $class Class to instantiate for the matched element
     */
    public function __construct(
        public string $path,
        public string $class,
    ) {}
}
