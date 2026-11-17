<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Struct;

use Buggregator\Trap\Support\Measure;

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

    /**
     * A share for people: `12.5%`, or `<0.1%` for a small one that would round to zero.
     */
    public static function formatPercent(int|float $percent): string
    {
        return $percent > 0 && $percent < 0.05 ? '<0.1%' : \sprintf('%.1f%%', $percent);
    }

    public function isMeasured(Peaks $peaks): bool
    {
        return $this->of($peaks->toCost()) > 0;
    }

    /**
     * @return int<0, max> A negative value, as XHProf's `mu` of calls that free more than they allocate, as zero.
     */
    public function of(Cost $cost): int
    {
        return \max(0, match ($this) {
            self::WallTime => $cost->wt,
            self::CpuTime => $cost->cpu,
            self::Memory => $cost->mu,
            self::PeakMemory => $cost->pmu,
            self::Count => $cost->ct,
        });
    }

    /**
     * A value for people: `1.50 ms`, `2.00 MB`, a negative one as zero.
     */
    public function format(int|float $value): string
    {
        $value = \max(0, (int) $value);
        return match ($this) {
            self::WallTime, self::CpuTime => match (true) {
                $value < 1_000 => "$value µs",
                $value < 1_000_000 => \sprintf('%.2f ms', (float) $value / 1e3),
                default => \sprintf('%.2f s', (float) $value / 1e6),
            },
            self::Memory, self::PeakMemory => Measure::memory($value),
            self::Count => (string) $value,
        };
    }
}
