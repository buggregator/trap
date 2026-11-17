<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Struct;

/**
 * A measure of the calls: times in microseconds, memory in bytes, counts of calls or samples.
 *
 * @internal
 */
enum Metric: string
{
    case WallTime = 'wt';
    case CpuTime = 'cpu';
    case Memory = 'mu';
    case PeakMemory = 'pmu';
    case Count = 'ct';

    /**
     * The metrics to rank the functions by, in the order of preference.
     */
    private const RANKED = [self::WallTime, self::CpuTime, self::Memory, self::Count];

    /**
     * The first ranked metric the profile measures: wall time unless the profiler has none.
     */
    public static function rank(Peaks $peaks): ?self
    {
        foreach (self::RANKED as $metric) {
            if ($metric->isMeasured($peaks)) {
                return $metric;
            }
        }

        return null;
    }

    /**
     * @return list<self> The metrics the profile measures: a profiler that doesn't measure a metric leaves it zero.
     */
    public static function measured(Peaks $peaks): array
    {
        return \array_values(\array_filter(self::cases(), static fn(self $metric): bool => $metric->isMeasured($peaks)));
    }

    public function isMeasured(Peaks $peaks): bool
    {
        return $this->of($peaks->toCost()) > 0;
    }

    /**
     * @return int<0, max>
     */
    public function of(Cost $cost): int
    {
        return match ($this) {
            self::WallTime => $cost->wt,
            self::CpuTime => $cost->cpu,
            self::Memory => $cost->mu,
            self::PeakMemory => $cost->pmu,
            self::Count => $cost->ct,
        };
    }
}
