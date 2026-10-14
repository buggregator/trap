<?php

declare(strict_types=1);

namespace Buggregator\Trap\Proto\Frame\Otlp\Trace;

/**
 * Attribute values are plain PHP values: string, int, float, bool, null, a list for `arrayValue`
 * and a string-keyed map for `kvlistValue`. `bytesValue` stays a base64 string.
 *
 * @internal
 * @psalm-internal Buggregator
 *
 * @psalm-type AttributeValue = scalar|null|array<array-key, mixed>
 * @psalm-type Attributes = array<array-key, AttributeValue>
 */
final class Attributes
{
    /**
     * An empty map is serialized as `{}` so that JSON readers do not get a list in its place.
     *
     * @param Attributes $attributes
     */
    public static function serialize(array $attributes): array|\stdClass
    {
        return $attributes === [] ? new \stdClass() : $attributes;
    }
}
