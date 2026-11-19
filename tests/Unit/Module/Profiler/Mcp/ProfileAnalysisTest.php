<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Mcp;

use Buggregator\Trap\Module\Profiler\Frame\Payload;
use Buggregator\Trap\Module\Profiler\Frame\Type;
use Buggregator\Trap\Module\Profiler\Mcp\ProfileAnalysis;
use Buggregator\Trap\Module\Profiler\Struct\Metric;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Module\Profiler\XDebug\ProfileBuilder;
use Buggregator\Trap\Support\TimeSlice;
use Buggregator\Trap\Tests\Unit\Module\Profiler\Mcp\Stub\Profiles;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ProfileAnalysis::class)]
final class ProfileAnalysisTest
{
    public function testConcurrentCallsAggregateOnce(): void
    {
        $calls = ['main()' => ['ct' => 1, 'wt' => 1_000, 'cpu' => 0, 'mu' => 0, 'pmu' => 0]];
        for ($i = 0; $i < 100; ++$i) {
            $calls["main()==>f$i"] = ['ct' => 1, 'wt' => 10, 'cpu' => 0, 'mu' => 0, 'pmu' => 0];
        }
        $analysis = self::analysis(Profiles::xhprof($calls));

        [$first, $second, $suspensions] = self::concurrently(
            static fn(): array => $analysis->functions(),
            static fn(): array => $analysis->functions(),
        );

        Assert::int($suspensions)->greaterThan(0);
        Assert::same($first['f1'], $second['f1']);
    }

    public function testConcurrentCallsUnfoldOnce(): void
    {
        $analysis = self::analysis(Profiles::xhprof(Profiles::lattice(levels: 4, chain: 5)));

        [$first, $second, $suspensions] = self::concurrently($analysis->tree(...), $analysis->tree(...));

        Assert::int($suspensions)->greaterThan(0);
        Assert::true($first === $second);
    }

    public function testPeakOfRootsPassingIntegerRangeIsClamped(): void
    {
        $analysis = self::analysis(Profiles::xhprof([
            'main()' => ['ct' => 1, 'wt' => \PHP_INT_MAX, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'shutdown()' => ['ct' => 1, 'wt' => \PHP_INT_MAX, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
        ]));

        Assert::same($analysis->peak(Metric::WallTime), \PHP_INT_MAX);
    }

    public function testPeakLeavesOutRootsOfXdebugFibers(): void
    {
        $profile = (new ProfileBuilder())->createProfile(
            (string) \file_get_contents(__DIR__ . '/../../../../Stub/xdebug/fibers/fibers.cachegrind'),
        );
        $analysis = self::analysis($profile);

        // `task` runs in a fiber: its 29 µs count in `{main}` too
        Assert::same($analysis->peak(Metric::WallTime), 79);
        Assert::same($analysis->peak(Metric::Memory), 17240);
    }

    /**
     * Every tick suspends the fiber, however fast the machine.
     */
    private static function analysis(Profile $profile): ProfileAnalysis
    {
        return new ProfileAnalysis(
            'id',
            Payload::new(Type::XHProf, static fn(): Profile => $profile),
            static fn(): TimeSlice => new TimeSlice(0.0, 1),
        );
    }

    /**
     * Runs both in fibers, resuming each in turn till both end.
     *
     * @return array{mixed, mixed, int} The results and the suspensions.
     */
    private static function concurrently(\Closure $first, \Closure $second): array
    {
        $fibers = [new \Fiber($first), new \Fiber($second)];
        $suspensions = 0;
        foreach ($fibers as $fiber) {
            $fiber->start();
        }
        while (!$fibers[0]->isTerminated() || !$fibers[1]->isTerminated()) {
            foreach ($fibers as $fiber) {
                if (!$fiber->isTerminated()) {
                    ++$suspensions;
                    $fiber->resume();
                }
            }
        }

        return [$fibers[0]->getReturn(), $fibers[1]->getReturn(), $suspensions];
    }
}
