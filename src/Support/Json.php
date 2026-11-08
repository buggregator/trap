<?php

declare(strict_types=1);

namespace Buggregator\Trap\Support;

/**
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class Json
{
    /**
     * @param int $flags `JSON_*` flags added to the default ones.
     * @param int<1, 2147483647> $depth Maximum nesting.
     * @throws \JsonException
     */
    public static function encode(mixed $value, int $flags = 0, int $depth = 512): string
    {
        // Never false: JSON_THROW_ON_ERROR is always set.
        return (string) \json_encode(
            $value,
            $flags
            | \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
            $depth,
        );
    }

    /**
     * @throws \JsonException
     */
    public static function decode(string $content): mixed
    {
        return \json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
    }
}
