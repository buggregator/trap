<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Frontend\Module\Profiler;

use Buggregator\Trap\Module\Frontend\Event;
use Buggregator\Trap\Module\Frontend\Module\Profiler\Mapper;
use Buggregator\Trap\Module\Frontend\Module\Profiler\Palette;
use Buggregator\Trap\Module\Profiler\XHProf\ProfileBuilder;
use Buggregator\Trap\Proto\Frame\Profiler\Payload;
use Buggregator\Trap\Proto\Frame\Profiler\Type;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(Mapper::class)]
#[Covers(Palette::class)]
#[Test]
final class MapperTest
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

    /**
     * `x()` is called from both `a()` and `b()` and calls `y()`.
     */
    private const SHARED_STUB = [
        'x==>y' => ['ct' => 1, 'wt' => 5, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
        'a==>x' => ['ct' => 1, 'wt' => 10, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
        'main()==>a' => ['ct' => 1, 'wt' => 20, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
        'b==>x' => ['ct' => 1, 'wt' => 30, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
        'main()==>b' => ['ct' => 1, 'wt' => 40, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
        'main()' => ['ct' => 1, 'wt' => 100, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
    ];

    public function testTopFunctionsSortedByExclusiveWallTime(): void
    {
        $top = self::json((new Mapper())->topFunctions(self::event(), 'excl_wt'));

        Assert::same(\array_column($top['functions'], 'function'), ['foo', 'bar', 'main()', 'strlen', 'xhprof_disable']);
        Assert::same(\array_column($top['functions'], 'excl_wt'), [64, 37, 34, 3, 1]);
        Assert::same($top['overall_totals']['wt'], 139);
    }

    public function testTopFunctionsCountRecursionOnceInInclusiveCost(): void
    {
        $top = self::json((new Mapper())->topFunctions(self::event(), 'wt'));
        $bar = self::find($top['functions'], 'bar');

        Assert::same($bar['wt'], 37);
        Assert::same($bar['excl_wt'], 37);
        Assert::same($bar['ct'], 3);
        Assert::same($bar['p_wt'], 26.619);
    }

    public function testTopFunctionsPercentagesOfTotals(): void
    {
        $top = self::json((new Mapper())->topFunctions(self::event(), 'wt'));
        $foo = self::find($top['functions'], 'foo');

        Assert::same($foo['p_wt'], 74.82);
        Assert::same($foo['p_excl_wt'], 46.043);
        Assert::same(\array_column($top['functions'], 'function')[0], 'main()');
    }

    public function testTopFunctionsSchemaHasExclusiveColumns(): void
    {
        $top = self::json((new Mapper())->topFunctions(self::event(), 'wt'));

        Assert::same(
            \array_column($top['schema'], 'key'),
            ['function', 'ct', 'cpu', 'excl_cpu', 'wt', 'excl_wt', 'mu', 'excl_mu', 'pmu', 'excl_pmu'],
        );
    }

    public function testCallGraphFiltersAndColorsNodes(): void
    {
        $graph = self::json((new Mapper())->callGraph(self::event(), threshold: 1, percentage: 15, metric: 'wt'));

        $nodes = \array_column(\array_column($graph['nodes'], 'data'), null, 'name');
        Assert::array(\array_keys($nodes))
            ->sameElementsAs(['main() (1x)', 'foo (1x)', 'bar (2x)', 'bar (1x)', 'strlen (2x)']);
        Assert::same(
            \array_map(static fn(array $n): array => [$n['color'], $n['textColor']], $nodes),
            [
                'bar (2x)' => ['#D93939', '#FFFFFF'],
                'strlen (2x)' => ['#FFFFFF', '#000000'],
                'bar (1x)' => ['#FFFFFF', '#000000'],
                'foo (1x)' => ['#540D0D', '#FFFFFF'],
                'main() (1x)' => ['#000000', '#FFFFFF'],
            ],
        );
        Assert::same($nodes['foo (1x)']['metrics'], ['cost' => 104, 'percents' => 74.82]);
    }

    public function testCallGraphLinksCallersToCallees(): void
    {
        $graph = self::json((new Mapper())->callGraph(self::event(), threshold: 1, percentage: 15, metric: 'wt'));

        $names = \array_column(\array_column($graph['nodes'], 'data'), 'name', 'id');
        $edges = \array_map(
            static fn(array $e): string => "{$names[$e['data']['source']]} > {$names[$e['data']['target']]}: {$e['data']['label']}",
            $graph['edges'],
        );
        Assert::same($edges, [
            'foo (1x) > bar (2x): 26.619%',
            'foo (1x) > strlen (2x): 2.158%',
            'bar (2x) > bar (1x): 1.439%',
            'main() (1x) > foo (1x): 74.82%',
        ]);
    }

    public function testCallGraphHasOneNodePerFunction(): void
    {
        $graph = self::json((new Mapper())->callGraph(self::event(self::SHARED_STUB), threshold: 0, percentage: 0));

        $names = \array_column(\array_column($graph['nodes'], 'data'), 'name', 'id');
        Assert::array(\array_values($names))->sameElementsAs(['main() (1x)', 'a (1x)', 'b (1x)', 'x (2x)', 'y (1x)']);
        $x = \array_values(\array_filter(\array_column($graph['nodes'], 'data'), static fn(array $n): bool => $n['name'] === 'x (2x)'))[0];
        Assert::same($x['metrics'], ['cost' => 40, 'percents' => 40]);
        Assert::count($graph['edges'], 5);
    }

    public function testTopFunctionsSumCallsFromAllCallers(): void
    {
        $top = self::json((new Mapper())->topFunctions(self::event(self::SHARED_STUB), 'wt'));
        $x = self::find($top['functions'], 'x');

        Assert::same([$x['ct'], $x['wt'], $x['excl_wt']], [2, 40, 35]);
        Assert::count($top['functions'], 5);
    }

    public function testFlameChartScalesSharedCalleeByCaller(): void
    {
        $chart = self::json((new Mapper())->flameChart(self::event(self::SHARED_STUB)));

        [$a, $b] = $chart[0]['children'];
        $yUnderA = $a['children'][0]['children'][0];
        $yUnderB = $b['children'][0]['children'][0];
        Assert::same([$yUnderA['name'], $yUnderA['duration'], $yUnderA['start']], ['y', 0.001, 0]);
        Assert::same([$yUnderB['name'], $yUnderB['duration'], $yUnderB['start']], ['y', 0.004, 0.02]);
        Assert::same($b['start'], 0.02);
    }

    public function testFlameChartSpansAreColoredByWallTime(): void
    {
        $chart = self::json((new Mapper())->flameChart(self::event()));

        $main = $chart[0];
        Assert::same($main['color'], '#FF5F5F');
        Assert::same(\array_column($main['children'], 'color'), ['#FF8989', '#B3E5FC']);
    }

    private static function event(array $calls = self::XHPROF_STUB): Event
    {
        $profile = (new ProfileBuilder())->createProfile(calls: $calls);

        return new Event(
            uuid: $profile->uuid,
            type: 'profiler',
            payload: Payload::new(Type::XHProf, static fn() => $profile),
            timestamp: 0.0,
        );
    }

    private static function json(\JsonSerializable $message): array
    {
        return \json_decode(\json_encode($message, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
    }

    private static function find(array $functions, string $name): array
    {
        foreach ($functions as $function) {
            if ($function['function'] === $name) {
                return $function;
            }
        }

        throw new \LogicException("Function `$name` not found.");
    }
}
