<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Mcp;

use Buggregator\Trap\Module\Profiler\Frame\Type;
use Buggregator\Trap\Module\Profiler\Mcp\ProfileAnalyses;
use Buggregator\Trap\Module\Profiler\Mcp\ProfileAnalysis;
use Buggregator\Trap\Module\Profiler\Mcp\ProfileCalls;
use Buggregator\Trap\Module\Profiler\Mcp\ProfileSummary;
use Buggregator\Trap\Module\Profiler\Mcp\ProfileTop;
use Buggregator\Trap\Support\TimeSlice;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\JsonSchema;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\Trap;
use Buggregator\Trap\Tests\Unit\Module\Profiler\Mcp\Stub\Profiles;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ProfileCalls::class)]
#[Covers(ProfileAnalysis::class)]
final class ProfileCallsTest
{
    public function testCallersAndCallees(): void
    {
        [$trap, $id] = self::shop();
        $tool = $trap->get(ProfileCalls::class);

        $result = $tool->call(['id' => $id, 'function' => 'App\Repo::find']);

        Assert::same($result['function'], [
            'function' => 'App\Repo::find',
            'calls' => 5,
            'inclusive' => 600,
            'inclusive_percent' => 60.0,
            'exclusive' => 200,
            'exclusive_percent' => 20.0,
        ]);
        Assert::same([$result['metric'], $result['unit'], $result['peak'], $result['root']], ['wt', 'microseconds', 1000, false]);
        // The own 140 µs of the outer calls are split by the callers' shares: 500 and 100 of 600
        Assert::same($result['callers'], [
            self::link('App\Controller::index', 'App\Repo::find', 1, 3, 500, 117),
            self::link('main()', 'App\Repo::find', 1, 1, 100, 23),
            self::link('App\Repo::find', 'App\Repo::find', 1, 1, 60, 60),
        ]);
        Assert::same($result['callees'], [
            self::link('PDO::query', 'App\Repo::find', 1, 3, 400, 400),
            self::link('App\Repo::find', 'App\Repo::find', 1, 1, 60, 60),
        ]);
        Assert::same([$result['callers_count'], $result['callees_count'], $result['truncated']], [3, 2, false]);
        Assert::same(JsonSchema::errors($result, $tool->outputSchema()), []);
    }

    public function testDepthFollowsCalleesOfCallees(): void
    {
        [$trap, $id] = self::shop();

        $result = $trap->get(ProfileCalls::class)->call(['id' => $id, 'function' => 'App\Controller::index', 'depth' => 2]);

        Assert::same(\array_map(static fn(array $row): array => [$row['depth'], $row['via'], $row['function']], $result['callees']), [
            [1, 'App\Controller::index', 'App\Repo::find'],
            [2, 'App\Repo::find', 'PDO::query'],
            [2, 'App\Repo::find', 'App\Repo::find'],
            [1, 'App\Controller::index', 'strlen'],
        ]);
        Assert::same(\array_column($result['callers'], 'function'), ['main()']);
        Assert::same($result['callees_count'], 2);
    }

    public function testDepthFollowsCallersOfCallers(): void
    {
        [$trap, $id] = self::shop();

        $result = $trap->get(ProfileCalls::class)->call(['id' => $id, 'function' => 'PDO::query', 'depth' => 3, 'limit' => 1]);

        Assert::same(\array_map(static fn(array $row): array => [$row['depth'], $row['function']], $result['callers']), [
            [1, 'App\Repo::find'],
            [2, 'App\Controller::index'],
            [3, 'main()'],
        ]);
        Assert::same($result['callees'], []);
    }

    public function testRootHasNoCallers(): void
    {
        [$trap, $id] = self::shop();

        $result = $trap->get(ProfileCalls::class)->call(['id' => $id, 'function' => 'main()', 'limit' => 1]);

        Assert::true($result['root']);
        Assert::same($result['callers'], []);
        Assert::same(\array_column($result['callees'], 'function'), ['App\Controller::index']);
        Assert::same($result['callees_count'], 2);
    }

    #[DataSet(['XHProf'], 'XHProf')]
    #[DataSet(['Xdebug'], 'Xdebug')]
    #[DataSet(['SPX'], 'SPX')]
    #[DataSet(['pprof'], 'pprof')]
    #[DataSet(['Speedscope'], 'Speedscope')]
    public function testValuesStayWithinTheirBoundsForEveryMetric(string $format): void
    {
        $trap = new Trap();
        [$type, $profile] = Profiles::formats()[$format];
        $id = Profiles::add($trap, $type, $profile());
        $tool = $trap->get(ProfileCalls::class);
        $metrics = $trap->get(ProfileSummary::class)->call(['id' => $id])['metrics'];

        foreach ($metrics as $metric) {
            $top = $trap->get(ProfileTop::class)->call(['id' => $id, 'metric' => $metric, 'limit' => 50]);
            $rows = [];
            foreach ($top['functions'] as $function) {
                $result = $tool->call(['id' => $id, 'function' => $function['function'], 'metric' => $metric, 'limit' => 50]);
                $rows = [...$rows, $result['function'], ...$result['callers'], ...$result['callees']];
            }

            Assert::notBlank($rows);
            foreach ($rows as $row) {
                self::assertBounds($row);
            }
        }
    }

    public function testRecursionsOfDifferentDepthsAreSummed(): void
    {
        $trap = new Trap();
        // `g@1()` calls `f@1` inside `h()`, and `g()` calls `f@1` directly: neither call is inside the other
        $id = Profiles::add($trap, Type::XHProf, Profiles::xhprof([
            'main()' => ['ct' => 1, 'wt' => 1000, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'main()==>f' => ['ct' => 1, 'wt' => 1000, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'f==>g' => ['ct' => 1, 'wt' => 900, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'g==>h' => ['ct' => 1, 'wt' => 800, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'h==>g@1' => ['ct' => 1, 'wt' => 700, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'g@1==>f@1' => ['ct' => 1, 'wt' => 600, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'g==>f@1' => ['ct' => 1, 'wt' => 50, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
        ]));
        $tool = $trap->get(ProfileCalls::class);

        $f = $tool->call(['id' => $id, 'function' => 'f']);
        $g = $tool->call(['id' => $id, 'function' => 'g']);

        $fromG = \array_column($f['callers'], null, 'function')['g'];
        Assert::same([$fromG['calls'], $fromG['inclusive']], [2, 650]);
        Assert::int($fromG['exclusive'])->lessThanOrEqual(650);
        Assert::same(\array_column($g['callees'], 'inclusive', 'function')['f'], 650);
    }

    public function testSignedMemoryIsClamped(): void
    {
        $trap = new Trap();
        // `free()` frees 300 bytes, so the own memory of `main()` is 400 bytes of its 100
        $id = Profiles::add($trap, Type::XHProf, Profiles::xhprof([
            'main()' => ['ct' => 1, 'wt' => 1000, 'cpu' => 0, 'mu' => 100, 'pmu' => 0],
            'main()==>free' => ['ct' => 1, 'wt' => 400, 'cpu' => 0, 'mu' => -300, 'pmu' => 0],
        ]));

        $result = $trap->get(ProfileCalls::class)->call(['id' => $id, 'function' => 'main()', 'metric' => 'mu']);

        Assert::same([$result['function']['inclusive'], $result['function']['exclusive']], [100, 100]);
        Assert::same([$result['callees'][0]['inclusive'], $result['callees'][0]['exclusive']], [0, 0]);
    }

    public function testRecursiveCallsOutsideTheRecursionAreSummed(): void
    {
        $trap = new Trap();
        // `g()` calls `f()` both inside an outer `f()` and outside of it
        $id = Profiles::add($trap, Type::XHProf, Profiles::xhprof([
            'main()' => ['ct' => 1, 'wt' => 1000, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'main()==>f' => ['ct' => 1, 'wt' => 500, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'f==>g' => ['ct' => 1, 'wt' => 400, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'g==>f@1' => ['ct' => 1, 'wt' => 300, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'main()==>g' => ['ct' => 1, 'wt' => 200, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'g==>f' => ['ct' => 1, 'wt' => 100, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
        ]));
        $tool = $trap->get(ProfileCalls::class);

        $f = $tool->call(['id' => $id, 'function' => 'f']);
        $g = $tool->call(['id' => $id, 'function' => 'g']);

        // 300 µs of `f@1` and the share of `g()` in the own 200 µs of `f()`: 100 of 600
        Assert::same($f['callers'][1], self::link('g', 'f', 1, 2, 400, 333));
        Assert::same($g['callees'][0], self::link('f', 'g', 1, 2, 400, 333));
    }

    #[DataSet(['XHProf'], 'XHProf')]
    #[DataSet(['Xdebug'], 'Xdebug')]
    #[DataSet(['SPX'], 'SPX')]
    #[DataSet(['pprof'], 'pprof')]
    #[DataSet(['Speedscope'], 'Speedscope')]
    public function testEveryFormat(string $format): void
    {
        $trap = new Trap();
        [$type, $profile] = Profiles::formats()[$format];
        $id = Profiles::add($trap, $type, $profile());
        $tool = $trap->get(ProfileCalls::class);
        $top = $trap->get(ProfileTop::class)->call(['id' => $id, 'sort' => 'inclusive', 'limit' => 2]);

        $result = $tool->call(['id' => $id, 'function' => $top['functions'][1]['function'], 'depth' => 2]);

        Assert::same($result['function'], $top['functions'][1]);
        Assert::notBlank([...$result['callers'], ...$result['callees']]);
        Assert::same(JsonSchema::errors($result, $tool->outputSchema()), []);
    }

    public function testListsAreCut(): void
    {
        $calls = ['main()' => ['ct' => 1, 'wt' => 1000, 'cpu' => 0, 'mu' => 0, 'pmu' => 0]];
        for ($i = 0; $i < 10; ++$i) {
            $calls["main()==>f$i"] = ['ct' => 1, 'wt' => 50, 'cpu' => 0, 'mu' => 0, 'pmu' => 0];
            for ($j = 0; $j < 30; ++$j) {
                $calls["f$i==>g$j"] = ['ct' => 1, 'wt' => 1, 'cpu' => 0, 'mu' => 0, 'pmu' => 0];
            }
        }
        $trap = new Trap();
        $id = Profiles::add($trap, Type::XHProf, Profiles::xhprof($calls));
        $tool = $trap->get(ProfileCalls::class);

        $cut = $tool->call(['id' => $id, 'function' => 'main()', 'depth' => 2, 'limit' => 50]);
        $limited = $tool->call(['id' => $id, 'function' => 'main()', 'depth' => 2, 'limit' => 5]);

        Assert::count($cut['callees'], 200);
        Assert::true($cut['truncated']);
        Assert::count($limited['callees'], 30);
        Assert::false($limited['truncated']);
        Assert::same($limited['callees_count'], 10);
    }

    /**
     * @param array<non-empty-string, array{ct: int, wt: int, cpu: int, mu: int, pmu: int}> $calls
     */
    #[DataSet([
        [
            'main()' => ['ct' => 1, 'wt' => 1000, 'cpu' => 0, 'mu' => 100, 'pmu' => 0],
            'main()==>a' => ['ct' => 1, 'wt' => 500, 'cpu' => 0, 'mu' => 80, 'pmu' => 0],
            'main()==>b' => ['ct' => 1, 'wt' => 400, 'cpu' => 0, 'mu' => -50, 'pmu' => 0],
            'b==>a' => ['ct' => 1, 'wt' => 300, 'cpu' => 0, 'mu' => 80, 'pmu' => 0],
        ],
        'a',
        'mu',
        160,
    ], 'signed memory summed over the callers')]
    #[DataSet([
        [
            'main()' => ['ct' => 1, 'wt' => 100, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'main()==>a' => ['ct' => 1, 'wt' => 100, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'a==>b' => ['ct' => 1, 'wt' => 90, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'b==>a' => ['ct' => 1, 'wt' => 80, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
        ],
        'a',
        'wt',
        180,
    ], 'cycle in received data')]
    public function testPercentagesStayWithin100(array $calls, string $function, string $metric, int $inclusive): void
    {
        $trap = new Trap();
        $id = Profiles::add($trap, Type::XHProf, Profiles::xhprof($calls));

        $result = $trap->get(ProfileCalls::class)->call(['id' => $id, 'function' => $function, 'metric' => $metric]);

        Assert::same([$result['function']['inclusive'], $result['function']['inclusive_percent']], [$inclusive, 100.0]);
    }

    public function testPercentagesOfProfileWithoutMainAreOfItsRoot(): void
    {
        $trap = new Trap();
        $id = Profiles::add($trap, Type::XHProf, Profiles::xhprof([
            'a==>x' => ['ct' => 1, 'wt' => 600, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'a==>y' => ['ct' => 1, 'wt' => 500, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
        ]));

        $result = $trap->get(ProfileCalls::class)->call(['id' => $id, 'function' => 'a']);

        Assert::same([$result['peak'], $result['root']], [1100, true]);
        Assert::same([$result['function']['inclusive'], $result['function']['inclusive_percent']], [1100, 100.0]);
        Assert::same(\array_column($result['callees'], 'inclusive_percent', 'function'), ['x' => 54.545, 'y' => 45.455]);
        Assert::same($trap->get(ProfileSummary::class)->call(['id' => $id])['peaks']['wt'], 1100);
    }

    public function testHubOfManyCallersAndCalleesTakesLinearTime(): void
    {
        // Walking the callees of the hub once per caller would take seconds
        $n = 5000;
        $cost = static fn(int $wt): array => ['ct' => 1, 'wt' => $wt, 'cpu' => 0, 'mu' => 0, 'pmu' => 0];
        $calls = ['main()' => $cost(10 * $n)];
        for ($i = 0; $i < $n; ++$i) {
            $calls["main()==>caller$i"] = $cost(5);
            $calls["caller$i==>hub"] = $cost(4);
            $calls["hub==>callee$i"] = $cost(1);
        }
        $trap = new Trap();
        $id = Profiles::add($trap, Type::XHProf, Profiles::xhprof($calls));
        $tool = new ProfileCalls(new ProfileAnalyses($trap->store, static fn(): TimeSlice => new TimeSlice(0.0, 1)));
        // The functions are summed and indexed here, so only the hub's calls are left to the fiber
        $tool->call(['id' => $id, 'function' => 'main()', 'limit' => 1]);
        $fiber = new \Fiber(static fn(): array => $tool->call(['id' => $id, 'function' => 'hub', 'limit' => 1]));
        $start = \hrtime(true);

        $suspensions = 0;
        $fiber->start();
        while (!$fiber->isTerminated()) {
            ++$suspensions;
            $fiber->resume();
        }

        $seconds = (\hrtime(true) - $start) / 1e9;
        Assert::int($suspensions)->greaterThan(0);
        Assert::same([$fiber->getReturn()['callers_count'], $fiber->getReturn()['callees_count']], [$n, $n]);
        Assert::true($seconds < 1.0, \sprintf('The calls of the hub took %.2f s.', $seconds));
    }

    public function testUnknownFunctionFailsWithCloseNames(): never
    {
        [$trap, $id] = self::shop();

        Expect::exception(\InvalidArgumentException::class)
            ->withMessage("Profile `$id` has no function `repo`. Close matches:\nApp\\Repo::find");

        $trap->get(ProfileCalls::class)->call(['id' => $id, 'function' => 'repo']);
    }

    public function testMisspelledFunctionFailsWithSimilarNames(): never
    {
        [$trap, $id] = self::shop();

        Expect::exception(\InvalidArgumentException::class)
            ->withMessage("Profile `$id` has no function `mian()`. Close matches:\nmain()");

        $trap->get(ProfileCalls::class)->call(['id' => $id, 'function' => 'mian()']);
    }

    public function testUnrelatedFunctionFails(): never
    {
        [$trap, $id] = self::shop();

        Expect::exception(\InvalidArgumentException::class)
            ->withMessage("Profile `$id` has no function `Symfony\\Kernel::boot`. Call `profile_top` for the names.");

        $trap->get(ProfileCalls::class)->call(['id' => $id, 'function' => 'Symfony\Kernel::boot']);
    }

    #[DataSet([['function' => ''], '`function` must be a non-empty string.'], 'empty function')]
    #[DataSet([[], '`function` must be a non-empty string.'], 'no function')]
    #[DataSet([['function' => 'main()', 'depth' => 4], '`depth` must be an integer from 1 to 3.'], 'too deep')]
    #[DataSet([['function' => 'main()', 'limit' => 51], '`limit` must be an integer from 1 to 50.'], 'limit over the maximum')]
    public function testInvalidArgumentFails(array $arguments, string $message): never
    {
        [$trap, $id] = self::shop();

        Expect::exception(\InvalidArgumentException::class)->withMessage($message);

        $trap->get(ProfileCalls::class)->call(['id' => $id] + $arguments);
    }

    /**
     * @return array{Trap, non-empty-string}
     */
    private static function shop(): array
    {
        $trap = new Trap();

        return [$trap, Profiles::add($trap, Type::XHProf, Profiles::xhprof())];
    }

    private static function assertBounds(array $row): void
    {
        Assert::int($row['inclusive'])->greaterThanOrEqual(0);
        Assert::int($row['exclusive'])->greaterThanOrEqual(0)->lessThanOrEqual($row['inclusive']);
        foreach (['inclusive_percent', 'exclusive_percent'] as $percent) {
            $row[$percent] === null or Assert::numeric($row[$percent])->between(0, 100);
        }
    }

    private static function link(string $function, string $via, int $depth, int $calls, int $inclusive, int $exclusive): array
    {
        return [
            'function' => $function,
            'via' => $via,
            'depth' => $depth,
            'calls' => $calls,
            'inclusive' => $inclusive,
            'inclusive_percent' => $inclusive / 10.0,
            'exclusive' => $exclusive,
            'exclusive_percent' => $exclusive / 10.0,
        ];
    }
}
