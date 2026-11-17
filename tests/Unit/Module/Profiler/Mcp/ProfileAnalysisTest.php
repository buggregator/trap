<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Mcp;

use Buggregator\Trap\Module\Profiler\Frame\Payload;
use Buggregator\Trap\Module\Profiler\Frame\Type;
use Buggregator\Trap\Module\Profiler\Mcp\ProfileAnalysis;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
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
        $calls = ['main()' => ['ct' => 1, 'wt' => 1_000_000, 'cpu' => 0, 'mu' => 0, 'pmu' => 0]];
        for ($i = 0; $i < 30_000; ++$i) {
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
        $analysis = self::analysis(Profiles::xhprof(Profiles::lattice(levels: 12, chain: 5)));

        [$first, $second, $suspensions] = self::concurrently($analysis->tree(...), $analysis->tree(...));

        Assert::int($suspensions)->greaterThan(0);
        Assert::true($first === $second);
    }

    private static function analysis(Profile $profile): ProfileAnalysis
    {
        return new ProfileAnalysis('id', Payload::new(Type::XHProf, static fn(): Profile => $profile));
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
