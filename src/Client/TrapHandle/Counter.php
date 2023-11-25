<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\TrapHandle;

final class Counter
{
    /** @var array<non-empty-string, int<0, max>> */
    private static array $counters = [];

    /**
     * Returns true if the counter of related stack trace is less than $times. In this case, the counter is incremented.
     */
    public static function checkAndIncrement(string $key, int $times): bool
    {
        self::$counters[$key] ??= 0;

        if (self::$counters[$key] < $times) {
            self::$counters[$key]++;
            return true;
        }

        return false;
    }
}
