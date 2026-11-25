<?php

declare(strict_types=1);

namespace Buggregator\Trap\Support;

/**
 * Bytes as `hexdump -C` prints them: the offset, 16 bytes in hex split in two halves, and the same bytes as ASCII
 * with a dot for any other byte.
 *
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class Hexdump
{
    public const ROW = 16;

    /**
     * @param int<0, max> $offset Of the first byte, printed in the offset column.
     *
     * @return list<array{int<0, max>, string, string}> The offset, the hex and the ASCII of each row of 16 bytes;
     *         the hex of a short last row padded to the width of a full one.
     */
    public static function rows(string $bytes, int $offset = 0): array
    {
        $rows = [];
        foreach ($bytes === '' ? [] : \str_split($bytes, self::ROW) as $i => $chunk) {
            $hex = \str_pad(\implode(' ', \str_split(\bin2hex($chunk), 2)), 47);
            $rows[] = [
                $offset + $i * self::ROW,
                \substr($hex, 0, 23) . ' ' . \substr($hex, 23),
                (string) \preg_replace('/[^\x20-\x7e]/', '.', $chunk),
            ];
        }

        return $rows;
    }

    /**
     * @param int<0, max> $offset Of the first byte.
     *
     * @return string Lines ending with a line feed; empty for no bytes.
     */
    public static function text(string $bytes, int $offset = 0): string
    {
        $text = '';
        foreach (self::rows($bytes, $offset) as [$at, $hex, $ascii]) {
            $text .= \sprintf("%08x  %s  |%s|\n", $at, $hex, $ascii);
        }

        return $text;
    }
}
