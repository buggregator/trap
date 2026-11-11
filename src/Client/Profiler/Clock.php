<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\Profiler;

/**
 * The clock a sampling profiler takes samples by.
 *
 * @api
 */
enum Clock: string
{
    /**
     * Real time: waiting on I/O, sleep and locks is sampled too.
     */
    case Wall = 'wall';

    /**
     * CPU time of the process: only the time spent computing is sampled.
     */
    case Cpu = 'cpu';
}
