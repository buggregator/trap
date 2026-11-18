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

    public function testHubOfManyCallersAndCalleesTakesLinearWork(): void
    {
        // Walking the callees of the hub once per caller would take 25 million ticks
        $n = 5000;
        $cost = static fn(int $wt): array => ['ct' => 1, 'wt' => $wt, 'cpu' => 0, 'mu' => 0, 'pmu' => 0];
        $calls = ['main()' => $cost(10 * $n)];
        for ($i = 0; $i < $n; ++$i) {
            $calls["main()==>caller$i"] = $cost(5);
            $calls["caller$i==>hub"] = $cost(4);
            $calls["hub==>callee$i"] = $cost(1);
        }

        [$result, $ticks] = self::callTicking($calls, 'hub', 10 * $n);

        Assert::same([$result['callers_count'], $result['callees_count']], [$n, $n]);
        Assert::int($ticks)->greaterThan(0);
    }

    public function testHubReachedFromManyCallersIsLinkedOnce(): void
    {
        // 50 rows reach the hub: linking it for each would take 600 000 ticks
        $n = 2000;
        $cost = static fn(int $wt): array => ['ct' => 1, 'wt' => $wt, 'cpu' => 0, 'mu' => 0, 'pmu' => 0];
        $calls = ['main()' => $cost(100 * $n)];
        for ($i = 0; $i < 50; ++$i) {
            $calls["main()==>c$i"] = $cost(2 * $n);
            $calls["c$i==>hub"] = $cost($n);
        }
        for ($i = 1; $i <= $n; ++$i) {
            $calls['hub' . ($i === 1 ? '' : '@' . ($i - 1)) . "==>hub@$i"] = $cost(1);
            $calls["hub@$i==>x"] = $cost(1);
        }

        [$result, $ticks] = self::callTicking($calls, 'main()', 20 * $n, ['depth' => 3, 'limit' => 50]);

        Assert::count($result['callees'], 200);
        Assert::false($result['truncated']);
        Assert::same(\array_count_values(\array_column($result['callees'], 'function'))['x'], 50);
        Assert::int($ticks)->greaterThan(0);
    }

    public function testManyRecursionLevelsOfOnePairTakeLinearWork(): void
    {
        // Neither call is inside another: the deeper `g()` is, the shallower `f()` is
        $n = 8000;
        $cost = static fn(int $wt): array => ['ct' => 1, 'wt' => $wt, 'cpu' => 0, 'mu' => 0, 'pmu' => 0];
        $calls = ['main()' => $cost(10 * $n), 'main()==>f' => $cost(1)];
        for ($i = 1; $i <= $n; ++$i) {
            $calls["g@$i==>f@" . ($n - $i + 1)] = $cost(2);
        }
        $start = \hrtime(true);

        [$result, $ticks] = self::callTicking($calls, 'f', 10 * $n);

        $seconds = (\hrtime(true) - $start) / 1e9;
        $fromG = \array_column($result['callers'], null, 'function')['g'];
        Assert::same([$fromG['calls'], $fromG['inclusive']], [$n, 2 * $n]);
        Assert::int($ticks)->greaterThan(0);
        // A secondary guard: comparing every pair of the calls takes half a minute
        Assert::true($seconds < 10.0, \sprintf('The calls took %.1f s.', $seconds));
    }

    public function testCallsDeeperInBothFunctionsThanAnotherCallAreNested(): void
    {
        $trap = new Trap();
        // `g@1()` calling `f@3` is inside `g()` calling `f@2`; `g@1()` calling `f@2` or `f@1` is not
        $id = Profiles::add($trap, Type::XHProf, Profiles::xhprof([
            'main()' => ['ct' => 1, 'wt' => 1000, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'main()==>g' => ['ct' => 1, 'wt' => 900, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'g==>f@2' => ['ct' => 1, 'wt' => 100, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'g@1==>f@3' => ['ct' => 1, 'wt' => 50, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'g@1==>f@2' => ['ct' => 1, 'wt' => 30, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'g@1==>f@1' => ['ct' => 1, 'wt' => 200, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
        ]));

        $result = $trap->get(ProfileCalls::class)->call(['id' => $id, 'function' => 'f']);

        Assert::same([$result['callers'][0]['function'], $result['callers'][0]['calls'], $result['callers'][0]['inclusive']], ['g', 4, 330]);
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

    public function testUnknownFunctionListsTenHeaviestCloseNames(): never
    {
        $calls = ['main()' => ['ct' => 1, 'wt' => 10_000, 'cpu' => 0, 'mu' => 0, 'pmu' => 0]];
        for ($i = 0; $i < 15; ++$i) {
            $calls["main()==>f$i"] = ['ct' => 1, 'wt' => 100 + $i, 'cpu' => 0, 'mu' => 0, 'pmu' => 0];
        }
        $trap = new Trap();
        $id = Profiles::add($trap, Type::XHProf, Profiles::xhprof($calls));

        Expect::exception(\InvalidArgumentException::class)
            ->withMessage("Profile `$id` has no function `F`. Close matches:\nf14\nf13\nf12\nf11\nf10\nf9\nf8\nf7\nf6\nf5");

        $trap->get(ProfileCalls::class)->call(['id' => $id, 'function' => 'F']);
    }

    public function testSimilarNamesAreSoughtAmongAtMostTheBoundOfHeaviestCandidates(): never
    {
        $bound = (new \ReflectionClassConstant(ProfileAnalysis::class, 'MAX_SIMILAR_CANDIDATES'))->getValue();
        $calls = [
            'main()' => ['ct' => 1, 'wt' => 1_000_000, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'main()==>zzzzy' => ['ct' => 1, 'wt' => 100_000, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'main()==>zzzza' => ['ct' => 1, 'wt' => 1, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
        ];
        // Heavier than `zzzza` and as long as the name sought, they push it past the candidates kept
        for ($i = 0; $i < $bound; ++$i) {
            $calls[\sprintf('main()==>a%04d', $i)] = ['ct' => 1, 'wt' => 10_000 - $i, 'cpu' => 0, 'mu' => 0, 'pmu' => 0];
        }
        $trap = new Trap();
        $id = Profiles::add($trap, Type::XHProf, Profiles::xhprof($calls));

        Expect::exception(\InvalidArgumentException::class)
            ->withMessage("Profile `$id` has no function `zzzzz`. Close matches:\nzzzzy");

        $trap->get(ProfileCalls::class)->call(['id' => $id, 'function' => 'zzzzz']);
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

    public function testDescribesItselfAsReadOnly(): void
    {
        $tool = (new Trap())->get(ProfileCalls::class);

        Assert::same($tool->name(), 'profile_calls');
        Assert::same($tool->title(), 'Callers and callees of a function in a profile');
        Assert::string($tool->description())->contains('`profile_top`')->contains('`depth` adds the callers of the callers');
        Assert::same($tool->annotations(), ['readOnlyHint' => true, 'openWorldHint' => false]);
    }

    #[DataSet(['depth'], 'depth')]
    #[DataSet(['limit'], 'limit')]
    public function testSchemaBoundsAreTheAcceptedArguments(string $argument): void
    {
        [$trap, $id] = self::shop();
        $tool = $trap->get(ProfileCalls::class);
        $property = $tool->inputSchema()['properties'][$argument];

        foreach ([$property['minimum'], $property['maximum']] as $bound) {
            $tool->call(['id' => $id, 'function' => 'main()', $argument => $bound]);
        }
        foreach ([$property['minimum'] - 1, $property['maximum'] + 1] as $outside) {
            try {
                $tool->call(['id' => $id, 'function' => 'main()', $argument => $outside]);
                Assert::fail("`$argument` of $outside must be rejected.");
            } catch (\InvalidArgumentException $e) {
                Assert::string($e->getMessage())->startsWith("`$argument` must be");
            }
        }
    }

    public function testSchemaListsTheArguments(): void
    {
        $schema = (new Trap())->get(ProfileCalls::class)->inputSchema();
        $properties = $schema['properties'];

        Assert::same(\array_keys($properties), ['id', 'function', 'metric', 'depth', 'limit']);
        Assert::same([$schema['required'], $schema['additionalProperties']], [['id', 'function'], false]);
        Assert::same($properties['id'], ProfileAnalyses::idSchema());
        Assert::same($properties['metric']['enum'], ['wt', 'cpu', 'mu', 'pmu', 'ct']);
    }

    #[DataSet(['depth'], 'depth')]
    #[DataSet(['limit'], 'limit')]
    public function testSchemaDefaultsAreTheDefaultsOfTheCall(string $argument): void
    {
        $trap = new Trap();
        // A function of 9 callers and 24 callees
        $base = ['id' => Profiles::add($trap, Type::XHProf, Profiles::xhprofApp()), 'function' => 'Id8\Id60::id99'];
        $tool = $trap->get(ProfileCalls::class);
        $property = $tool->inputSchema()['properties'][$argument];
        $default = $property['default'];

        Assert::same($tool->call($base), $tool->call($base + [$argument => $default]));
        foreach ([$default - 1, $default + 1] as $other) {
            $other < $property['minimum'] or Assert::notSame(
                $tool->call($base + [$argument => $other]),
                $tool->call($base),
                "`$argument` of $other must change the result.",
            );
        }
    }

    /**
     * @return array{Trap, non-empty-string}
     */
    private static function shop(): array
    {
        $trap = new Trap();

        return [$trap, Profiles::add($trap, Type::XHProf, Profiles::xhprof())];
    }

    /**
     * Calls the tool for the function in a fiber suspended at every tick: the ticks count the work,
     * deterministically on any machine.
     *
     * @param positive-int $maxTicks Fails past them rather than run the unbounded work to the end.
     * @param array<non-empty-string, mixed> $arguments Of the tool, besides `id` and `function`; `limit` is 1 by default.
     *
     * @return array{array, int} The result and the ticks.
     */
    private static function callTicking(array $calls, string $function, int $maxTicks, array $arguments = []): array
    {
        $trap = new Trap();
        $id = Profiles::add($trap, Type::XHProf, Profiles::xhprof($calls));
        $tool = new ProfileCalls(new ProfileAnalyses($trap->store, static fn(): TimeSlice => new TimeSlice(0.0, 1)));
        // The functions are summed and indexed here, so only the calls of the function are left to the fiber
        $tool->call(['id' => $id, 'function' => 'main()', 'limit' => 1]);
        $arguments = ['id' => $id, 'function' => $function] + $arguments + ['limit' => 1];
        $fiber = new \Fiber(static fn(): array => $tool->call($arguments));

        $ticks = 0;
        $fiber->start();
        while (!$fiber->isTerminated()) {
            ++$ticks <= $maxTicks or Assert::fail("The calls of `$function` took more than $maxTicks ticks.");
            $fiber->resume();
        }

        return [$fiber->getReturn(), $ticks];
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
