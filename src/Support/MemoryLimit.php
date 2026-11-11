<?php

declare(strict_types=1);

namespace Buggregator\Trap\Support;

/**
 * Reads and raises `memory_limit`.
 *
 * @internal
 */
final class MemoryLimit
{
    /**
     * @return int<1, max>|null Bytes, or null for no limit.
     */
    public static function parse(string $value): ?int
    {
        $value = \trim($value);
        if ($value === '' || $value === '-1') {
            return null;
        }

        $number = (int) $value;
        $bytes = match (\strtolower(\substr($value, -1))) {
            'g' => $number << 30,
            'm' => $number << 20,
            'k' => $number << 10,
            default => $number,
        };

        return $bytes > 0 ? $bytes : null;
    }

    /**
     * @return int<1, max>|null The current limit in bytes, or null for no limit.
     */
    public static function current(): ?int
    {
        return self::parse((string) \ini_get('memory_limit'));
    }

    /**
     * Raises the limit to the value when it is lower; never lowers it, nor limits an unlimited process.
     *
     * @param string $value A `memory_limit` value; an empty one or `0` leaves the limit as it is.
     *
     * @return bool Whether the limit was raised.
     */
    public static function raise(string $value): bool
    {
        $value = \trim($value);
        $current = self::current();
        if ($value === '' || $value === '0' || $current === null) {
            return false;
        }

        $target = self::parse($value);
        if ($target === null ? $value !== '-1' : $target <= $current) {
            return false;
        }

        return \ini_set('memory_limit', $target === null ? '-1' : (string) $target) !== false;
    }
}
