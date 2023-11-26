<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\TrapHandle;

final class Counter
{
    /** @var array<string, int<0, max>> */
    private static array $counters = [];

    /**
     * Returns true if the counter of related stack trace is less than $times. In this case, the counter is incremented.
     *
     * @param int<0, max> $times
     */
    public static function checkAndIncrement(string $key, int $times): bool
    {
        self::$counters[$key] ??= 0;

        if (self::$counters[$key] < $times) {
            ++self::$counters[$key];
            return true;
        }

        return false;
    }
}
