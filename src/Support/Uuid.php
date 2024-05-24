<?php

declare(strict_types=1);

namespace Buggregator\Trap\Support;

/**
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class Uuid
{
    /**
     * Generate UUID using default algorithm.
     *
     * @return non-empty-string
     */
    public static function generate(): string
    {
        return self::uuid4();
    }

    /**
     * @return non-empty-string UUID v4
     */
    public static function uuid4(): string
    {
        $data = \random_bytes(16);

        $data[6] = \chr(\ord($data[6]) & 0x0f | 0x40); // set version to 0100
        $data[8] = \chr(\ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10

        /** @var non-empty-string $uuid */
        $uuid = \vsprintf('%s%s-%s-%s-%s-%s%s%s', \str_split(\bin2hex($data), 4));

        return $uuid;
    }
}
