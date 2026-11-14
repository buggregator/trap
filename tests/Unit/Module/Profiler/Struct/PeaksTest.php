<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Struct;

use Buggregator\Trap\Module\Profiler\Struct\Cost;
use Buggregator\Trap\Module\Profiler\Struct\Peaks;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Peaks::class)]
final class PeaksTest
{
    public function roundTripsThroughArray(): void
    {
        $data = ['ct' => 1, 'wt' => 2, 'cpu' => 3, 'mu' => 4, 'pmu' => 5];

        Assert::same(Peaks::fromArray($data)->jsonSerialize(), $data);
    }

    public function updateKeepsTheMaximumOfEachMetric(): void
    {
        $peaks = new Peaks(ct: 5, wt: 10, cpu: 8, mu: 100, pmu: 7);

        $peaks->update(new Cost(ct: 3, wt: 20, cpu: 3, mu: 50, pmu: 9));

        Assert::same($peaks->jsonSerialize(), ['ct' => 5, 'wt' => 20, 'cpu' => 8, 'mu' => 100, 'pmu' => 9]);
    }

    public function addSumsTimesAndKeepsMemoryPeaks(): void
    {
        $peaks = new Peaks(ct: 5, wt: 10, cpu: 4, mu: 50, pmu: 7);

        // Every metric tells apart keeping, summing and taking the maximum
        $peaks->add(new Cost(ct: 9, wt: 20, cpu: 6, mu: 100, pmu: 9));

        Assert::same($peaks->jsonSerialize(), ['ct' => 5, 'wt' => 30, 'cpu' => 10, 'mu' => 100, 'pmu' => 9]);
    }

    public function convertsToCost(): void
    {
        $cost = (new Peaks(ct: 1, wt: 2, cpu: 3, mu: 4, pmu: 5))->toCost();

        Assert::same([$cost->ct, $cost->wt, $cost->cpu, $cost->mu, $cost->pmu], [1, 2, 3, 4, 5]);
    }
}
