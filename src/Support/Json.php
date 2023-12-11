<?php

declare(strict_types=1);

namespace Buggregator\Trap\Support;

/**
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class Json
{
    public static function encode(mixed $value): string
    {
        return \json_encode(
            $value,
            \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );
    }
}
