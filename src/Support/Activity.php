<?php

declare(strict_types=1);

namespace Buggregator\Trap\Support;

/**
 * Lets the code in fibers tell the main loop that it made progress, so the loop runs the next iteration
 * without waiting: see {@see LoopPacer}.
 *
 * Report it before suspending a fiber that has more work to do; a fiber that waits for data must not.
 *
 * @internal
 */
final class Activity
{
    private static bool $reported = false;

    public static function report(): void
    {
        self::$reported = true;
    }

    /**
     * @return bool Whether there was a report since the previous call.
     */
    public static function take(): bool
    {
        $reported = self::$reported;
        self::$reported = false;
        return $reported;
    }
}
