<?php

declare(strict_types=1);

namespace Buggregator\Client\Support;

/**
 * @internal
 * @psalm-internal Buggregator\Client
 */
final class Uuid
{
    /**
     * Generate UUID using default algorithm.
     */
    public static function generate(): string
    {
        return self::uuid4();
    }

    public static function uuid4(): string
    {
        $data = \random_bytes(16);

        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10

        return \vsprintf('%s%s-%s-%s-%s-%s%s%s', \str_split(\bin2hex($data), 4));
    }
}
