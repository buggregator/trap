<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\Profiler\Internal;

/**
 * A running profiler started by {@see Driver::start()}.
 *
 * @internal
 */
interface Recording
{
    /**
     * Stops the profiler. Called once.
     */
    public function stop(): Recorded;
}
