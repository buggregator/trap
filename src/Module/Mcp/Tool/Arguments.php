<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp\Tool;

/**
 * Checks of the tool arguments, which come not validated against the input schema.
 *
 * @internal
 */
final class Arguments
{
    /**
     * @param array<array-key, mixed> $arguments
     * @param int<0, max> $min
     * @param int<0, max> $max
     *
     * @return int<0, max>|null Null when the argument is missing.
     *
     * @throws \InvalidArgumentException
     */
    public static function integer(array $arguments, string $name, int $min = 0, int $max = \PHP_INT_MAX): ?int
    {
        /** @var mixed $value */
        $value = $arguments[$name] ?? null;
        if ($value === null) {
            return null;
        }

        // JSON has one number type: a client may send 10.0
        \is_float($value) && \floor($value) === $value and $value = (int) $value;
        \is_int($value) && $value >= $min && $value <= $max or throw new \InvalidArgumentException(\sprintf(
            '`%s` must be an integer from %d to %d.',
            $name,
            $min,
            $max,
        ));
        \assert($value >= 0);

        return $value;
    }
}
