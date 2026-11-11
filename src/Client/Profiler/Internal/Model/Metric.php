<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\Profiler\Internal\Model;

/**
 * A metric a profile may have measured; the cases match the {@see Metrics} properties.
 *
 * @internal
 */
enum Metric: string
{
    case WallTime = 'wt';
    case CpuTime = 'cpu';
    case Memory = 'mu';
    case PeakMemory = 'pmu';
    case Calls = 'ct';
    case Samples = 'samples';
}
