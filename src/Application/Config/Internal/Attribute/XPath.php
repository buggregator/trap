<?php

declare(strict_types=1);

namespace Buggregator\Trap\Application\Config\Internal\Attribute;

/**
 * XPath configuration attribute.
 *
 * Maps a property to an XML element or attribute using XPath expression.
 *
 * @internal
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class XPath implements ConfigAttribute
{
    /**
     * @param non-empty-string $path XPath expression
     * @param int<0, max> $key Index in the result array (if XPath returns multiple items)
     */
    public function __construct(
        public string $path,
        public int $key = 0,
    ) {}
}
