<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Support;

use Buggregator\Trap\Support\TimeSlice;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(TimeSlice::class)]
#[Test]
final class TimeSliceTest
{
    public function testSuspendsFiberWithoutValueOnceSliceIsUsedUp(): void
    {
        $slice = new TimeSlice(0.0);
        $fiber = new \Fiber(static function () use ($slice): void {
            for ($i = 0; $i < 1000; ++$i) {
                $slice->tick();
            }
        });

        $suspended = [];
        $value = $fiber->start();
        while (!$fiber->isTerminated()) {
            $suspended[] = $value;
            $value = $fiber->resume();
        }

        // The clock is read every 256 ticks
        Assert::same($suspended, [null, null, null]);
    }

    public function testKeepsRunningWithinSlice(): void
    {
        $slice = new TimeSlice(3600.0);
        $fiber = new \Fiber(static function () use ($slice): void {
            for ($i = 0; $i < 1000; ++$i) {
                $slice->tick();
            }
        });

        $fiber->start();

        Assert::true($fiber->isTerminated());
    }

    public function testNeverSuspendsOutsideFiber(): void
    {
        $slice = new TimeSlice(0.0);

        $suspended = false;
        for ($i = 0; $i < 1000; ++$i) {
            $suspended = $slice->tick() || $suspended;
        }

        Assert::false($suspended);
    }
}
