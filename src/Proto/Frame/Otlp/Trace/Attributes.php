<?php

declare(strict_types=1);

namespace Buggregator\Trap\Proto\Frame\Otlp\Trace;

/**
 * Attribute values are plain PHP values: string, int, float, bool, null, a list for `arrayValue`
 * and an {@see \ArrayObject} for `kvlistValue`. `bytesValue` stays a base64 string, and a non-finite
 * `doubleValue` is one of the strings `NaN`, `Infinity`, `-Infinity`.
 *
 * A map is an object rather than an array because a map with keys `"0"`, `"1"`, … or with no keys
 * is indistinguishable from a list as a PHP array and would be written to JSON as one.
 *
 * @internal
 * @psalm-internal Buggregator
 *
 * @psalm-type AttributeValue = scalar|null|list<mixed>|\ArrayObject
 * @psalm-type Attributes = array<array-key, AttributeValue>
 */
final class Attributes
{
    /**
     * @param Attributes $attributes
     * @return \ArrayObject<array-key, AttributeValue>
     */
    public static function serialize(array $attributes): \ArrayObject
    {
        return new \ArrayObject($attributes);
    }

    /**
     * Restores attributes from JSON decoded with objects, the form that keeps maps apart from lists.
     *
     * @return Attributes
     */
    public static function fromJson(mixed $map): array
    {
        $result = [];
        if ($map instanceof \stdClass) {
            /** @var mixed $value */
            foreach (\get_object_vars($map) as $key => $value) {
                $result[$key] = self::valueFromJson($value);
            }
        }

        return $result;
    }

    /**
     * @return AttributeValue
     */
    private static function valueFromJson(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \stdClass => new \ArrayObject(self::fromJson($value)),
            \is_array($value) => \array_values(\array_map(self::valueFromJson(...), $value)),
            \is_scalar($value) => $value,
            default => null,
        };
    }
}
