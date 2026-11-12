<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Pprof;

/**
 * Reads the protobuf wire format from a string, between byte offsets: the messages are not copied out.
 *
 * Groups, deprecated in proto3 and absent from pprof, are malformed data here.
 *
 * @internal
 */
final class Wire
{
    public const VARINT = 0;
    public const FIXED64 = 1;
    public const LEN = 2;
    public const FIXED32 = 5;

    /**
     * A varint of up to 64 bits: an unsigned value over `PHP_INT_MAX` wraps to a negative integer,
     * as a negative int64 is encoded.
     *
     * @param int $offset Moves past the varint.
     *
     * @throws \InvalidArgumentException When the varint is truncated or longer than 64 bits.
     */
    public static function varint(string $data, int &$offset, int $end): int
    {
        $result = 0;
        $shift = 0;
        do {
            $offset < $end or throw new \InvalidArgumentException("Truncated varint at byte $offset.");
            $byte = \ord($data[$offset++]);
            $shift === 63 && $byte > 1
                and throw new \InvalidArgumentException('A varint is longer than 64 bits at byte ' . ($offset - 1) . '.');
            $result |= ($byte & 0x7F) << $shift;
            $shift += 7;
        } while ($byte >= 0x80);

        return $result;
    }

    /**
     * @param int $offset Moves past the tag.
     *
     * @return array{int<1, max>, int} The field number and the wire type.
     *
     * @throws \InvalidArgumentException When the tag is malformed or the wire type is unknown or a group.
     */
    public static function tag(string $data, int &$offset, int $end): array
    {
        $at = $offset;
        $tag = self::varint($data, $offset, $end);
        $field = $tag >> 3;
        $type = $tag & 7;
        $field > 0 && $field <= 0x1FFFFFFF or throw new \InvalidArgumentException("Invalid field number at byte $at.");
        \in_array($type, [self::VARINT, self::FIXED64, self::LEN, self::FIXED32], true)
            or throw new \InvalidArgumentException("Unsupported wire type $type of field $field at byte $at.");

        return [$field, $type];
    }

    /**
     * @param int $offset Moves past the length, to the first byte of the content.
     *
     * @return int End offset of the length-delimited content.
     *
     * @throws \InvalidArgumentException When the content goes past the end.
     */
    public static function length(string $data, int &$offset, int $end): int
    {
        $length = self::varint($data, $offset, $end);
        $length >= 0 && $length <= $end - $offset
            or throw new \InvalidArgumentException("A length-delimited field at byte $offset goes past its end.");

        return $offset + $length;
    }

    /**
     * Skips the value of a field.
     *
     * @param int $offset Moves past the value.
     */
    public static function skip(string $data, int &$offset, int $end, int $type): void
    {
        match ($type) {
            self::VARINT => self::varint($data, $offset, $end),
            self::LEN => $offset = self::length($data, $offset, $end),
            default => $offset = self::fixed($offset, $end, $type === self::FIXED64 ? 8 : 4),
        };
    }

    /**
     * Reads a repeated varint field, packed or not.
     *
     * @param list<int> $values Gets the values.
     * @param int<1, max> $max Values allowed in the list.
     * @param int $offset Moves past the field.
     *
     * @throws \InvalidArgumentException When there are more than `$max` values.
     */
    public static function repeated(string $data, int &$offset, int $end, int $type, array &$values, int $max): void
    {
        $type === self::VARINT || $type === self::LEN
            or throw new \InvalidArgumentException("Wire type $type of a repeated integer field at byte $offset.");
        $to = $type === self::LEN ? self::length($data, $offset, $end) : $end;
        do {
            if ($type === self::LEN && $offset === $to) {
                return;
            }
            \count($values) < $max or throw new \InvalidArgumentException("More than $max values in a repeated field.");
            $values[] = self::varint($data, $offset, $to);
        } while ($type === self::LEN);
    }

    /**
     * @param int $offset Moves past the value.
     *
     * @throws \InvalidArgumentException When the field is not a varint.
     */
    public static function int(string $data, int &$offset, int $end, int $type): int
    {
        $type === self::VARINT or throw new \InvalidArgumentException("Wire type $type of an integer field at byte $offset.");

        return self::varint($data, $offset, $end);
    }

    /**
     * @param int $offset Moves to the first byte of the content.
     *
     * @return int End offset of the content.
     *
     * @throws \InvalidArgumentException When the field is not length-delimited.
     */
    public static function message(string $data, int &$offset, int $end, int $type): int
    {
        $type === self::LEN
            or throw new \InvalidArgumentException("Wire type $type of a length-delimited field at byte $offset.");

        return self::length($data, $offset, $end);
    }

    private static function fixed(int $offset, int $end, int $size): int
    {
        $offset + $size <= $end or throw new \InvalidArgumentException("Truncated fixed-size field at byte $offset.");

        return $offset + $size;
    }
}
