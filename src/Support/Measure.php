<?php

declare(strict_types=1);

namespace Buggregator\Trap\Support;

/**
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class Measure
{
    /**
     * @param int<0, max> $size
     * @return non-empty-string
     */
    public static function memory(int $size): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
        $power = (int) \floor(\log($size, 1024));
        $float = $power > 0 ? \round($size / (1024 ** $power), 2) : $size;

        \assert($power >= 0 && $power <= 5);

        return \sprintf('%s %s', \number_format($float, 2), $units[$power]);
    }
}
