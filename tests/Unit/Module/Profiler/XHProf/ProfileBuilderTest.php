<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\XHProf;

use Buggregator\Trap\Module\Profiler\Struct\Edge;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Module\Profiler\XHProf\ProfileBuilder;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(ProfileBuilder::class)]
#[Test]
final class ProfileBuilderTest
{
    /**
     * Raw xhprof output of:
     *
     * ```
     * function bar($x) { if ($x > 0) { bar($x - 1); } }
     * function foo() { for ($idx = 0; $idx < 2; $idx++) { bar($idx); $x = strlen("abc"); } }
     * xhprof_enable();
     * foo();
     * $xhprof_data = xhprof_disable();
     * ```
     */
    private const XHPROF_STUB = [
        'foo==>bar' => ['ct' => 2, 'wt' => 37, 'cpu' => 0, 'mu' => 2208, 'pmu' => 0],
        'foo==>strlen' => ['ct' => 2, 'wt' => 3, 'cpu' => 0, 'mu' => 624, 'pmu' => 0],
        'bar==>bar@1' => ['ct' => 1, 'wt' => 2, 'cpu' => 0, 'mu' => 856, 'pmu' => 0],
        'main()==>foo' => ['ct' => 1, 'wt' => 104, 'cpu' => 0, 'mu' => 4168, 'pmu' => 0],
        'main()==>xhprof_disable' => ['ct' => 1, 'wt' => 1, 'cpu' => 0, 'mu' => 344, 'pmu' => 0],
        'main()' => ['ct' => 1, 'wt' => 139, 'cpu' => 0, 'mu' => 5936, 'pmu' => 0],
    ];

    public function testPassesDateMetadataAndTagsThrough(): void
    {
        $date = new \DateTimeImmutable('2024-01-02 03:04:05');

        $profile = (new ProfileBuilder())->createProfile(
            date: $date,
            metadata: ['app_name' => 'app', 'hostname' => 'host'],
            tags: ['env' => 'prod'],
        );

        Assert::same($profile->date, $date);
        Assert::same($profile->metadata, ['app_name' => 'app', 'hostname' => 'host']);
        Assert::same($profile->tags, ['env' => 'prod']);
        Assert::same(\count($profile->calls), 0);
        Assert::same($profile->peaks->jsonSerialize(), ['ct' => 0, 'wt' => 0, 'cpu' => 0, 'mu' => 0, 'pmu' => 0]);
    }

    public function testBuildsCallGraphFromCallerCalleeKeys(): void
    {
        $profile = self::build([
            'a==>b' => self::cost(1, 30, 20, 100, 200),
            'main()==>a' => self::cost(1, 60, 40, 300, 400),
            'main()' => self::cost(1, 100, 80, 500, 1000),
        ]);

        $calls = $profile->calls;
        Assert::same(self::ids($calls->roots()), ['main()']);
        Assert::same(self::ids($calls->callees('main()')), ['a']);
        Assert::same(self::ids($calls->callees('a')), ['b']);
        Assert::same($calls->functions(), ['b', 'a', 'main()']);
        Assert::same(self::edge($profile, 'main()')->caller, null);
        Assert::same(self::edge($profile, 'a')->caller, 'main()');
        Assert::same(self::edge($profile, 'b')->caller, 'a');
    }

    public function testKeepsEveryCallerOfSharedCallee(): void
    {
        $profile = self::build([
            'x==>y' => self::cost(1, 5, 0, 0, 0),
            'a==>x' => self::cost(1, 10, 0, 0, 0),
            'main()==>a' => self::cost(1, 20, 0, 0, 0),
            'b==>x' => self::cost(1, 30, 0, 0, 0),
            'main()==>b' => self::cost(1, 40, 0, 0, 0),
            'main()' => self::cost(1, 100, 0, 0, 0),
        ]);

        $calls = $profile->calls;
        Assert::same(\count($calls), 6);
        Assert::same(\array_map(static fn(Edge $e): ?string => $e->caller, $calls->callers('x')), ['a', 'b']);
        Assert::same(self::ids($calls->callees('x')), ['y']);
        Assert::same($calls->inclusiveCost('x')->wt, 40);
        foreach ($calls->callers('x') as $edge) {
            Assert::same($edge->cost->p_wt > 0, true);
        }
    }

    public function testComputesPeaksAsMaximumOfEveryMetric(): void
    {
        $profile = self::build([
            'main()==>a' => self::cost(7, 60, 90, 300, 400),
            'main()' => self::cost(1, 100, 80, 500, 300),
        ]);

        Assert::same($profile->peaks->jsonSerialize(), ['ct' => 7, 'wt' => 100, 'cpu' => 90, 'mu' => 500, 'pmu' => 400]);
    }

    public function testComputesPercentagesOfPeaks(): void
    {
        $profile = self::build([
            'main()==>a' => self::cost(1, 30, 20, 100, 300),
            'main()' => self::cost(4, 90, 60, 400, 900),
        ]);

        $cost = self::edge($profile, 'a')->cost;
        Assert::same($cost->p_ct, 25.0);
        Assert::same($cost->p_wt, 33.333);
        Assert::same($cost->p_cpu, 33.333);
        Assert::same($cost->p_mu, 25.0);
        Assert::same($cost->p_pmu, 33.333);
        $root = self::edge($profile, 'main()')->cost;
        Assert::same([$root->p_ct, $root->p_wt, $root->p_cpu, $root->p_mu, $root->p_pmu], [100.0, 100.0, 100.0, 100.0, 100.0]);
    }

    public function testZeroPeaksGiveZeroPercentages(): void
    {
        $profile = self::build([
            'main()' => self::cost(0, 0, 0, 0, 0),
        ]);

        $cost = self::edge($profile, 'main()')->cost;
        Assert::same([$cost->p_ct, $cost->p_wt, $cost->p_cpu, $cost->p_mu, $cost->p_pmu], [0.0, 0.0, 0.0, 0.0, 0.0]);
    }

    public function testComputesDeltaAgainstParent(): void
    {
        $profile = self::build([
            'main()==>a' => self::cost(3, 60, 50, 700, 400),
            'main()' => self::cost(1, 100, 80, 500, 1000),
        ]);

        $cost = self::edge($profile, 'a')->cost;
        Assert::same([$cost->d_ct, $cost->d_wt, $cost->d_cpu, $cost->d_mu, $cost->d_pmu], [2, -40, -30, 200, -600]);
        $root = self::edge($profile, 'main()')->cost;
        Assert::same([$root->d_ct, $root->d_wt, $root->d_cpu, $root->d_mu, $root->d_pmu], [0, 0, 0, 0, 0]);
    }

    public function testComputesDeltaAgainstAllCallsOfParent(): void
    {
        $profile = self::build([
            'x==>y' => self::cost(1, 5, 0, 0, 0),
            'a==>x' => self::cost(1, 10, 0, 0, 0),
            'b==>x' => self::cost(1, 30, 0, 0, 0),
            'main()==>a' => self::cost(1, 20, 0, 0, 0),
            'main()==>b' => self::cost(1, 40, 0, 0, 0),
            'main()' => self::cost(1, 100, 0, 0, 0),
        ]);

        Assert::same(self::edge($profile, 'y')->cost->d_wt, -35);
    }

    public function testValueKeyBecomesMainWhenMainIsAbsent(): void
    {
        $profile = self::build([
            'main()==>a' => self::cost(1, 60, 40, 300, 400),
            'value' => self::cost(1, 100, 80, 500, 1000),
        ]);

        Assert::same(self::ids($profile->calls->roots()), ['main()']);
        Assert::same($profile->calls->functions(), ['a', 'main()']);
        Assert::same(self::edge($profile, 'main()')->cost->wt, 100);
    }

    public function testValueKeyIsDroppedWhenMainIsPresent(): void
    {
        $profile = self::build([
            'main()' => self::cost(1, 100, 80, 500, 1000),
            'value' => self::cost(1, 999, 999, 999, 999),
        ]);

        Assert::same($profile->calls->functions(), ['main()']);
        Assert::same(self::edge($profile, 'main()')->cost->wt, 100);
        Assert::same($profile->peaks->wt, 100);
    }

    public function testEmptyCallerIsTreatedAsRoot(): void
    {
        $profile = self::build([
            '==>main()' => self::cost(1, 100, 80, 500, 1000),
        ]);

        Assert::same(self::ids($profile->calls->roots()), ['main()']);
        Assert::same(self::edge($profile, 'main()')->caller, null);
    }

    public function testCreatesSyntheticRootForUnknownCallers(): void
    {
        $profile = self::build([
            'x==>c' => self::cost(2, 30, 20, 300, 100),
            'x==>b' => self::cost(1, 10, 5, 100, 400),
            'main()' => self::cost(1, 100, 80, 500, 1000),
        ]);

        $calls = $profile->calls;
        Assert::same(self::ids($calls->roots()), ['main()', 'x']);
        Assert::same(self::ids($calls->callees('x')), ['c', 'b']);
        $x = self::edge($profile, 'x');
        Assert::same($x->caller, null);
        Assert::same([$x->cost->wt, $x->cost->cpu, $x->cost->mu, $x->cost->pmu], [40, 25, 300, 400]);
        Assert::same($x->cost->p_wt, 40.0);
        Assert::same($profile->peaks->wt, 100);
    }

    public function testComputesDeltaForChildOfSyntheticRoot(): void
    {
        $profile = self::build([
            'x==>c' => self::cost(2, 30, 20, 300, 100),
            'x==>b' => self::cost(1, 10, 5, 100, 400),
        ]);

        $cost = self::edge($profile, 'b')->cost;
        Assert::same([$cost->d_wt, $cost->d_cpu, $cost->d_mu, $cost->d_pmu], [-30, -20, -200, 0]);
    }

    public function testRecursionLevelIsStrippedFromName(): void
    {
        $profile = self::build([
            'bar==>bar@1' => self::cost(1, 2, 0, 0, 0),
            'main()==>bar' => self::cost(1, 10, 0, 0, 0),
            'main()' => self::cost(1, 100, 0, 0, 0),
        ]);

        $edge = self::edge($profile, 'bar@1');
        Assert::same($edge->callee, 'bar');
        Assert::same($edge->calleeId, 'bar@1');
        Assert::same($edge->callerId, 'bar');
    }

    public function testXhprofOutputBuildsGraphInCallOrder(): void
    {
        $calls = self::build(self::XHPROF_STUB)->calls;

        Assert::array($calls->functions())
            ->sameElementsAs(['main()', 'foo', 'xhprof_disable', 'bar', 'strlen', 'bar@1']);
        Assert::same(self::ids($calls->roots()), ['main()']);
        Assert::same(self::ids($calls->callees('main()')), ['foo', 'xhprof_disable']);
        Assert::same(self::ids($calls->callees('foo')), ['bar', 'strlen']);
        Assert::same(self::ids($calls->callees('bar')), ['bar@1']);
        Assert::same($calls->callees('bar')[0]->callee, 'bar');
        Assert::same($calls->callees('bar')[0]->cost->wt, 2);
    }

    /**
     * @param array<non-empty-string, array{ct: int, wt: int, cpu: int, mu: int, pmu: int}> $calls
     */
    private static function build(array $calls): Profile
    {
        return (new ProfileBuilder())->createProfile(calls: $calls);
    }

    /**
     * First call of the function.
     */
    private static function edge(Profile $profile, string $calleeId): Edge
    {
        return $profile->calls->callers($calleeId)[0];
    }

    /**
     * @param list<Edge> $edges
     *
     * @return list<non-empty-string>
     */
    private static function ids(array $edges): array
    {
        return \array_map(static fn(Edge $e): string => $e->calleeId, $edges);
    }

    /**
     * @return array{ct: int, wt: int, cpu: int, mu: int, pmu: int}
     */
    private static function cost(int $ct, int $wt, int $cpu, int $mu, int $pmu): array
    {
        return ['ct' => $ct, 'wt' => $wt, 'cpu' => $cpu, 'mu' => $mu, 'pmu' => $pmu];
    }
}
