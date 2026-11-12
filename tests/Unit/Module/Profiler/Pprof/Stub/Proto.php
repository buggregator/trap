<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Pprof\Stub;

/**
 * Writes the protobuf wire format, to make pprof fixtures.
 */
final class Proto
{
    public static function varint(int $value): string
    {
        $bytes = '';
        do {
            $byte = $value & 0x7F;
            // Logical shift: a negative value takes 10 bytes, as int64 is encoded
            $value = ($value >> 7) & (\PHP_INT_MAX >> 6);
            $bytes .= \chr($value === 0 ? $byte : $byte | 0x80);
        } while ($value !== 0);

        return $bytes;
    }

    public static function tag(int $field, int $type): string
    {
        return self::varint($field << 3 | $type);
    }

    public static function int(int $field, int $value): string
    {
        return self::tag($field, 0) . self::varint($value);
    }

    public static function bytes(int $field, string $bytes): string
    {
        return self::tag($field, 2) . self::varint(\strlen($bytes)) . $bytes;
    }

    /**
     * @param list<int> $values
     */
    public static function repeated(int $field, array $values, bool $packed = true): string
    {
        if (!$packed) {
            return \implode('', \array_map(static fn(int $value): string => self::int($field, $value), $values));
        }

        return $values === [] ? '' : self::bytes($field, \implode('', \array_map(self::varint(...), $values)));
    }

    /**
     * Fields of every wire type a reader must skip: varint, fixed64, length-delimited, fixed32.
     */
    public static function unknownFields(int $from = 100): string
    {
        return self::int($from, 300)
            . self::tag($from + 1, 1) . \str_repeat("\xFF", 8)
            . self::bytes($from + 2, "\x08\x01nested")
            . self::tag($from + 3, 5) . "\x01\x02\x03\x04";
    }
}
