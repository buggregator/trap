<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Tracing\Http\OtlpTrap;

/**
 * Counts records of a protobuf message on the wire, without building it: building a message of a million
 * empty records with the pure-PHP `google/protobuf` takes over a minute and hundreds of megabytes.
 *
 * Malformed data ends the count; the protobuf parser reports it later.
 *
 * @internal
 * @psalm-internal Buggregator\Trap\Module\Tracing\Http
 */
final class ProtobufWire
{
    private const WIRE_VARINT = 0;
    private const WIRE_FIXED64 = 1;
    private const WIRE_LEN = 2;
    private const WIRE_FIXED32 = 5;

    /**
     * Counts the messages at the end of a path of nested length-delimited fields; stops past `$limit`.
     *
     * @param non-empty-list<non-empty-list<int>> $path Field numbers taken at each level, as
     *        `[[1], [2], [2]]` for `resource_spans[].scope_spans[].spans[]`.
     * @return int<0, max> At most `$limit + 1`.
     */
    public static function count(string $payload, array $path, int $limit): int
    {
        $count = 0;
        self::walk($payload, 0, \strlen($payload), $path, $limit, $count);

        return $count;
    }

    /**
     * @param list<non-empty-list<int>> $path
     * @param int<0, max> $count
     */
    private static function walk(string $bytes, int $from, int $to, array $path, int $limit, int &$count): void
    {
        $numbers = \array_shift($path);
        if ($numbers === null) {
            return;
        }

        foreach (self::fields($bytes, $from, $to, $numbers) as [$fieldFrom, $fieldTo]) {
            if ($path === []) {
                if (++$count > $limit) {
                    return;
                }
                continue;
            }

            self::walk($bytes, $fieldFrom, $fieldTo, $path, $limit, $count);
            if ($count > $limit) {
                return;
            }
        }
    }

    /**
     * Length-delimited fields of the given numbers in a message that spans `$offset..$end` of `$bytes`.
     *
     * @param list<int> $numbers
     * @return \Generator<int, array{int, int}> Offsets of the start and the end of each field value.
     */
    private static function fields(string $bytes, int $offset, int $end, array $numbers): \Generator
    {
        while ($offset < $end) {
            $tag = self::varint($bytes, $offset, $end);
            if ($tag === null) {
                return;
            }

            switch ($tag & 7) {
                case self::WIRE_VARINT:
                    if (self::varint($bytes, $offset, $end) === null) {
                        return;
                    }
                    break;
                case self::WIRE_FIXED64:
                    $offset += 8;
                    break;
                case self::WIRE_FIXED32:
                    $offset += 4;
                    break;
                case self::WIRE_LEN:
                    $length = self::varint($bytes, $offset, $end);
                    if ($length === null || $length > $end - $offset) {
                        return;
                    }
                    \in_array($tag >> 3, $numbers, true) and yield [$offset, $offset + $length];
                    $offset += $length;
                    break;
                default:
                    // Deprecated groups are not used by OTLP.
                    return;
            }
        }
    }

    /**
     * Reads a varint at `$offset` and moves the offset past it.
     *
     * @return int<0, max>|null Null when the varint is truncated. The 64th bit is dropped:
     *         tags and lengths never use it, and other values are only skipped.
     */
    private static function varint(string $bytes, int &$offset, int $end): ?int
    {
        $value = 0;
        for ($i = 0; $i < 10 && $offset < $end; ++$i) {
            $byte = \ord($bytes[$offset++]);
            $i < 9 and $value |= ($byte & 0x7F) << (7 * $i);
            if ($byte < 0x80) {
                \assert($value >= 0);
                return $value;
            }
        }

        return null;
    }
}
