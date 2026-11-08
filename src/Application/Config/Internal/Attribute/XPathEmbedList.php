<?php

declare(strict_types=1);

namespace Buggregator\Trap\Application\Config\Internal\Attribute;

/**
 * XPath embedding list configuration attribute.
 *
 * Maps a property to a list of objects by loading each from an XML element
 * that matches the XPath expression.
 *
 * @internal
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class XPathEmbedList implements ConfigAttribute
{
    /**
     * @param non-empty-string $path XPath expression to locate elements
     * @param class-string $class Class to instantiate for each matched element
     */
    public function __construct(
        public string $path,
        public string $class,
    ) {}
}
