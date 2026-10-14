<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\XHProf;

use Buggregator\Trap\Module\Profiler\Struct\Edge;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Module\Profiler\XHProf\ProfileBuilder;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Skip;
use Testo\Test;

#[Covers(ProfileBuilder::class)]
#[Test]
final class ProfileBuilderTest
{
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

    public function testBuildsCallTreeFromCallerCalleeKeys(): void
    {
        $profile = self::build([
            'a==>b' => self::cost(1, 30, 20, 100, 200),
            'main()==>a' => self::cost(1, 60, 40, 300, 400),
            'main()' => self::cost(1, 100, 80, 500, 1000),
        ]);

        $calls = $profile->calls;
        Assert::same(\array_keys($calls->root), ['main()']);
        Assert::same(\array_keys($calls->all), ['main()', 'a', 'b']);
        Assert::same($calls->lostChildren, []);
        Assert::same($calls->all['b']->parent, $calls->all['a']);
        Assert::same(self::edge($profile, 'main()')->caller, null);
        Assert::same(self::edge($profile, 'a')->caller, 'main()');
        Assert::same(self::edge($profile, 'b')->caller, 'a');
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

    public function testValueKeyBecomesMainWhenMainIsAbsent(): void
    {
        $profile = self::build([
            'main()==>a' => self::cost(1, 60, 40, 300, 400),
            'value' => self::cost(1, 100, 80, 500, 1000),
        ]);

        Assert::same(\array_keys($profile->calls->root), ['main()']);
        Assert::same(\array_keys($profile->calls->all), ['main()', 'a']);
        Assert::same(self::edge($profile, 'main()')->cost->wt, 100);
    }

    public function testValueKeyIsDroppedWhenMainIsPresent(): void
    {
        $profile = self::build([
            'main()' => self::cost(1, 100, 80, 500, 1000),
            'value' => self::cost(1, 999, 999, 999, 999),
        ]);

        Assert::same(\array_keys($profile->calls->all), ['main()']);
        Assert::same(self::edge($profile, 'main()')->cost->wt, 100);
        Assert::same($profile->peaks->wt, 100);
    }

    public function testEmptyCallerIsTreatedAsRoot(): void
    {
        $profile = self::build([
            '==>main()' => self::cost(1, 100, 80, 500, 1000),
        ]);

        Assert::same(\array_keys($profile->calls->root), ['main()']);
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
        Assert::same(\array_keys($calls->root), ['main()', 'x']);
        Assert::same($calls->lostChildren, []);
        Assert::same(\array_map(static fn($b): string => $b->id, $calls->all['x']->children), ['b', 'c']);
        $x = self::edge($profile, 'x');
        Assert::same($x->caller, null);
        Assert::same([$x->cost->wt, $x->cost->cpu, $x->cost->mu, $x->cost->pmu], [40, 25, 300, 400]);
        Assert::same($x->cost->p_wt, 40.0);
        Assert::same($profile->peaks->wt, 100);
    }

    #[Skip('Bug: Tree does not set the parent of an adopted lost child')]
    public function testComputesDeltaForChildOfSyntheticRoot(): void
    {
        $profile = self::build([
            'x==>c' => self::cost(2, 30, 20, 300, 100),
            'x==>b' => self::cost(1, 10, 5, 100, 400),
        ]);

        $cost = self::edge($profile, 'b')->cost;
        Assert::same([$cost->d_wt, $cost->d_cpu, $cost->d_mu, $cost->d_pmu], [-30, -20, -200, 0]);
    }

    /**
     * @param array<non-empty-string, array{ct: int, wt: int, cpu: int, mu: int, pmu: int}> $calls
     */
    private static function build(array $calls): Profile
    {
        return (new ProfileBuilder())->createProfile(calls: $calls);
    }

    private static function edge(Profile $profile, string $callee): Edge
    {
        return $profile->calls->all[$callee]->item;
    }

    /**
     * @return array{ct: int, wt: int, cpu: int, mu: int, pmu: int}
     */
    private static function cost(int $ct, int $wt, int $cpu, int $mu, int $pmu): array
    {
        return ['ct' => $ct, 'wt' => $wt, 'cpu' => $cpu, 'mu' => $mu, 'pmu' => $pmu];
    }
}
