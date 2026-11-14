<?php

declare(strict_types=1);

namespace Buggregator\Trap\Support;

/**
 * Lets a long computation share the process with other fibers: {@see self::tick()} suspends the current fiber
 * once the slice of time is used up.
 *
 * The fiber is suspended with no value, so the code that resumes it must take a null as "resume me later".
 * Outside a fiber it never suspends.
 *
 * @internal
 */
final class TimeSlice
{
    private int $ticks = 0;
    private int $start;

    /** @var int<1, max> */
    private readonly int $length;

    /**
     * @param float $seconds Time to run before letting the other fibers run.
     * @param positive-int $checkEvery Ticks between the clock reads: a read costs more than a tick of the usual loop.
     */
    public function __construct(
        float $seconds = 0.02,
        private readonly int $checkEvery = 256,
    ) {
        $this->length = \max(1, (int) ($seconds * 1e9));
        $this->start = \hrtime(true);
    }

    /**
     * Starts the slice again: the fiber has just let the others run.
     */
    public function reset(): void
    {
        $this->ticks = 0;
        $this->start = \hrtime(true);
    }

    /**
     * @return bool Whether the fiber was suspended.
     */
    public function tick(): bool
    {
        if (++$this->ticks < $this->checkEvery) {
            return false;
        }

        $this->ticks = 0;
        if (\hrtime(true) - $this->start < $this->length || \Fiber::getCurrent() === null) {
            return false;
        }

        Activity::report();
        \Fiber::suspend();
        $this->start = \hrtime(true);
        return true;
    }
}
