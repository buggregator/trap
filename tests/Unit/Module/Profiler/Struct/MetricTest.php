<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Struct;

use Buggregator\Trap\Module\Profiler\Struct\Cost;
use Buggregator\Trap\Module\Profiler\Struct\Metric;
use Buggregator\Trap\Module\Profiler\Struct\Peaks;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Test]
#[Covers(Metric::class)]
final class MetricTest
{
    #[DataSet([new Peaks(ct: 1, wt: 5, cpu: 3, mu: 2, pmu: 4), Metric::WallTime], 'wall time first')]
    #[DataSet([new Peaks(ct: 1, cpu: 3, mu: 2), Metric::CpuTime], 'then CPU time')]
    #[DataSet([new Peaks(ct: 1, mu: 2, pmu: 4), Metric::Memory], 'then memory')]
    #[DataSet([new Peaks(ct: 1, pmu: 4), Metric::Count], 'peak memory is not ranked by')]
    #[DataSet([new Peaks(), null], 'nothing measured')]
    public function testRanksByFirstMeasured(Peaks $peaks, ?Metric $metric): void
    {
        Assert::same(Metric::rank($peaks), $metric);
    }

    public function testMeasured(): void
    {
        Assert::same(Metric::measured(new Peaks(ct: 3, wt: 5, mu: 2)), [Metric::WallTime, Metric::Memory, Metric::Count]);
        Assert::same(Metric::measured(new Peaks()), []);
    }

    public function testValueOfCost(): void
    {
        $cost = new Cost(ct: 1, wt: 2, cpu: 3, mu: 4, pmu: 5);

        Assert::same(\array_map(static fn(Metric $metric): int => $metric->of($cost), Metric::cases()), [2, 3, 4, 5, 1]);
    }
}
