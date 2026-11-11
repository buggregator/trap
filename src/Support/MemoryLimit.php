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
     * Reads a value as PHP reads an ini quantity: a decimal, `0x` hexadecimal, `0o` octal or `0b` binary number,
     * with an optional `K`, `M` or `G` multiplier. Quantities past the integer range are clamped to it.
     *
     * Only `-1` means no limit. PHP takes other negatives as huge limits, and an unreadable value as 0:
     * such values are refused here, so that a caller can fall back to something safe.
     *
     * @return int<1, max>|null Bytes, or null for no limit.
     *
     * @throws \InvalidArgumentException When the value is not a positive quantity nor `-1`.
     */
    public static function parse(string $value): ?int
    {
        $value = \trim($value);
        if ($value === '-1') {
            return null;
        }

        \preg_match('/^(?:0x([\da-f]+)|0o([0-7]+)|0b([01]+)|(\d+))\s*([kmg]?)$/i', $value, $m) === 1
            or throw new \InvalidArgumentException("Unreadable memory limit `$value`.");
        /** @var int|float $number Float past the integer range */
        $number = match (true) {
            $m[1] !== '' => \hexdec($m[1]),
            $m[2] !== '' => \octdec($m[2]),
            $m[3] !== '' => \bindec($m[3]),
            default => (float) $m[4] >= (float) \PHP_INT_MAX ? (float) $m[4] : (int) $m[4],
        };
        $shift = match (\strtolower($m[5])) {
            'g' => 30,
            'm' => 20,
            'k' => 10,
            default => 0,
        };

        $bytes = \is_float($number) || $number > \PHP_INT_MAX >> $shift ? \PHP_INT_MAX : $number << $shift;
        $bytes > 0 or throw new \InvalidArgumentException("Memory limit `$value` is not positive.");

        return $bytes;
    }

    /**
     * @return int<1, max>|null The current limit in bytes, or null for no limit.
     *
     * @throws \InvalidArgumentException When the current value is unreadable.
     */
    public static function current(): ?int
    {
        return self::parse((string) \ini_get('memory_limit'));
    }

    /**
     * Raises the limit to the value when it is lower; never lowers it, nor limits an unlimited process.
     *
     * @param string $value A `memory_limit` value; an empty one leaves the limit as it is.
     *
     * @return bool Whether the limit was raised. Not when the value or the current limit is unreadable.
     */
    public static function raise(string $value): bool
    {
        try {
            $current = self::current();
            $target = \trim($value) === '' ? false : self::parse($value);
        } catch (\InvalidArgumentException) {
            return false;
        }

        if ($current === null || $target === false || ($target !== null && $target <= $current)) {
            return false;
        }

        return \ini_set('memory_limit', $target === null ? '-1' : (string) $target) !== false;
    }
}
