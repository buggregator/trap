<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Frontend;

use Buggregator\Trap\Module\Profiler\Frame as ProfilerFrame;
use Buggregator\Trap\Module\Profiler\Frame\Payload;
use Buggregator\Trap\Module\Profiler\Frame\Type;
use Buggregator\Trap\Module\Profiler\Frontend\Mapper;
use Buggregator\Trap\Module\Profiler\Frontend\Palette;
use Buggregator\Trap\Module\Profiler\Pprof\ProfileBuilder as PprofProfileBuilder;
use Buggregator\Trap\Module\Profiler\Speedscope\ProfileBuilder as SpeedscopeProfileBuilder;
use Buggregator\Trap\Module\Profiler\SPX\ProfileBuilder as SpxProfileBuilder;
use Buggregator\Trap\Module\Profiler\Stacks\ProfileBuilder as StacksProfileBuilder;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Module\Profiler\XDebug\ProfileBuilder as XDebugProfileBuilder;
use Buggregator\Trap\Module\Profiler\XHProf\ProfileBuilder;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Support\Spx\Metadata;
use Buggregator\Trap\Tests\Unit\Module\Profiler\Pprof\Stub\PprofProfile;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(Mapper::class)]
#[Covers(Palette::class)]
#[Covers(Recursion::class)]
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

    /**
     * `main` calls `a`, `b` and `c`; `a` calls `j` that calls `z`, `b` calls `j` that calls nothing,
     * `c` calls `k` that calls `z`. With an 8% threshold `j` (8%) and `k` (7%) are hidden, `z` (12%) is not.
     */
    private const BYPASS_XHPROF = [
        'j==>z' => ['ct' => 1, 'wt' => 6, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
        'a==>j' => ['ct' => 1, 'wt' => 7, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
        'main()==>a' => ['ct' => 1, 'wt' => 10, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
        'b==>j' => ['ct' => 1, 'wt' => 1, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
        'main()==>b' => ['ct' => 1, 'wt' => 10, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
        'k==>z' => ['ct' => 1, 'wt' => 6, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
        'c==>k' => ['ct' => 1, 'wt' => 7, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
        'main()==>c' => ['ct' => 1, 'wt' => 10, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
        'main()' => ['ct' => 1, 'wt' => 100, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
    ];

    /**
     * The same calls in the Xdebug format: own times in 10 ns, a block per call in the order the calls end.
     */
    private const BYPASS_CACHEGRIND = <<<'CACHEGRIND'
        version: 1
        creator: xdebug 3.4.0 (PHP 8.4.0)
        cmd: /app/bypass.php
        part: 1
        positions: line

        events: Time_(10ns) Memory_(bytes)

        fn=(1) z
        1 600 0

        fn=(2) j
        1 100 0
        cfn=(1)
        calls=1 0 0
        1 600 0

        fn=(3) a
        1 300 0
        cfn=(2)
        calls=1 0 0
        1 700 0

        fn=(2)
        1 100 0

        fn=(4) b
        1 900 0
        cfn=(2)
        calls=1 0 0
        1 100 0

        fn=(1)
        1 600 0

        fn=(5) k
        1 100 0
        cfn=(1)
        calls=1 0 0
        1 600 0

        fn=(6) c
        1 300 0
        cfn=(5)
        calls=1 0 0
        1 700 0

        fn=(7) main
        1 7000 0
        cfn=(3)
        calls=1 0 0
        1 1000 0
        cfn=(4)
        calls=1 0 0
        1 1000 0
        cfn=(6)
        calls=1 0 0
        1 1000 0

        CACHEGRIND;

    /**
     * `{main}` calls `C` that calls `A` that calls `B`, and calls `B` that calls `A`. The inner `A` and `B`
     * allocate 50 KB each, the outer ones free 49 KB of it: a call takes more memory than its caller.
     */
    private const ISLAND_CACHEGRIND = <<<'CACHEGRIND'
        version: 1
        creator: xdebug 3.4.0 (PHP 8.4.23)
        cmd: /app/island.php
        part: 1
        positions: line

        events: Time_(10ns) Memory_(bytes)

        fl=(1) /app/island.php
        fn=(1) B
        2 100 50000

        fl=(1)
        fn=(2) A
        3 100 -49000
        cfl=(1)
        cfn=(1)
        calls=1 0 0
        3 100 50000

        fl=(1)
        fn=(3) C
        4 100 0
        cfl=(1)
        cfn=(2)
        calls=1 0 0
        4 200 1000

        fl=(1)
        fn=(2)
        3 100 50000

        fl=(1)
        fn=(1)
        2 100 -49000
        cfl=(1)
        cfn=(2)
        calls=1 0 0
        2 100 50000

        fl=(1)
        fn=(4) {main}
        1 100 0
        cfl=(1)
        cfn=(3)
        calls=1 0 0
        5 300 1000
        cfl=(1)
        cfn=(1)
        calls=1 0 0
        6 200 1000

        summary: 900 2000

        CACHEGRIND;

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

    public function testFunctionsNamedByDigits(): void
    {
        $event = self::event([
            '10==>20' => ['ct' => 1, 'wt' => 30, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'main()==>10' => ['ct' => 1, 'wt' => 40, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'main()' => ['ct' => 1, 'wt' => 100, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
        ]);

        $top = self::json((new Mapper())->topFunctions($event, 'excl_wt'));
        $graph = self::json((new Mapper())->callGraph($event, threshold: 0, percentage: 0));

        Assert::same(
            \array_map(static fn(array $row): array => [$row['function'], $row['excl_wt']], $top['functions']),
            [['main()', 60], ['20', 30], ['10', 10]],
        );
        Assert::same(\array_column(\array_column($graph['nodes'], 'data'), 'name'), ['20 (1x)', '10 (1x)', 'main() (1x)']);
    }

    public function testTopFunctionsSchemaSkipsMetricsNotMeasured(): void
    {
        // The stub has no CPU time and no peak memory
        $top = self::json((new Mapper())->topFunctions(self::event(), 'wt'));

        Assert::same(\array_column($top['schema'], 'key'), ['function', 'ct', 'wt', 'excl_wt', 'mu', 'excl_mu']);
    }

    public function testCallGraphToolbarSkipsMetricsNotMeasured(): void
    {
        $graph = self::json((new Mapper())->callGraph(self::event()));

        Assert::same(\array_column($graph['toolbar'], 'metric'), ['wt', 'mu']);
    }

    public function testSampledProfileShowsSamplesAndOnlyTime(): void
    {
        $profile = (new SpeedscopeProfileBuilder())->createProfile([
            'shared' => ['frames' => [['name' => 'main'], ['name' => 'foo']]],
            'profiles' => [['type' => 'sampled', 'unit' => 'milliseconds', 'samples' => [[0, 1], [0]], 'weights' => [1, 1]]],
        ]);
        $event = new Event($profile->uuid, 'profiler', Payload::new(Type::Speedscope, static fn() => $profile), 0.0);

        $top = self::json((new Mapper())->topFunctions($event, 'wt'));
        $graph = self::json((new Mapper())->callGraph($event, threshold: 0));

        Assert::same(\array_column($top['schema'], 'key'), ['function', 'ct', 'wt', 'excl_wt']);
        Assert::same($top['schema'][1]['description'], 'Samples with the function on the stack');
        Assert::same(\array_column($graph['toolbar'], 'metric'), ['wt']);
        Assert::same(\array_column(\array_column($graph['nodes'], 'data'), 'name'), ['main() (2 samples)', 'foo (1 sample)']);
    }

    public function testSpxProfileShowsCallsOrSampledCallsWhenSampled(): void
    {
        $spx = Metadata::fromJson(
            '{"enabled_metrics": ["wt"], "called_function_count": 2, "recorded_call_count": 2}',
        );
        $trace = "[events]\n0 1 0\n1 1 1000\n1 0 2000\n0 0 3000\n[functions]\nmain\nfoo\n";
        $names = [];
        $descriptions = [];
        foreach ([null, 100] as $period) {
            $profile = (new SpxProfileBuilder())->createProfile($spx, [(string) \gzencode($trace)], samplingPeriod: $period);
            $event = new Event($profile->uuid, 'profiler', Payload::new(Type::SPX, static fn() => $profile), 0.0);
            $graph = self::json((new Mapper())->callGraph($event, threshold: 0));
            $names[] = \array_column(\array_column($graph['nodes'], 'data'), 'name');
            $descriptions[] = self::json((new Mapper())->topFunctions($event, 'wt'))['schema'][1]['description'];
        }

        Assert::same($names, [['main() (1x)', 'foo (1x)'], ['main() (1 sampled call)', 'foo (1 sampled call)']]);
        Assert::same($descriptions, ['Calls', 'Calls seen at the sampling ticks; a call over several ticks counts once']);
    }

    public function testPprofProfileShowsSamples(): void
    {
        $pprof = new PprofProfile([['samples', 'count'], ['alloc_space', 'bytes']]);
        $pprof->sample([$pprof->frame('foo'), $pprof->frame('main')], [1, 512])->sample([2], [1, 256]);
        $builder = new PprofProfileBuilder();
        $profile = $builder->createProfile($builder->decode($pprof->encode()));
        $event = new Event($profile->uuid, 'profiler', Payload::new(Type::Pprof, static fn() => $profile), 0.0);

        $top = self::json((new Mapper())->topFunctions($event, 'mu'));
        $graph = self::json((new Mapper())->callGraph($event, threshold: 0, metric: 'mu'));

        Assert::same(\array_column($top['schema'], 'key'), ['function', 'ct', 'mu', 'excl_mu']);
        Assert::same($top['schema'][1]['description'], 'Samples with the function on the stack');
        Assert::same(\array_column(\array_column($graph['nodes'], 'data'), 'name'), ['main() (2 samples)', 'foo (1 sample)']);
    }

    public function testStackSamplesShowSamples(): void
    {
        $builder = new StacksProfileBuilder();
        $profiles = [
            Type::Phpspy->value => $builder->phpspy("0 foo /app/a.php:1\n1 <main> /app/a.php:2\n\n0 <main> /app/a.php:3\n\n", period: 0.01),
            Type::Collapsed->value => $builder->collapsed("<main>;foo 1\n<main> 1\n", period: 0.01),
        ];

        foreach ($profiles as $type => $profile) {
            $payload = Payload::new(Type::from($type), static fn() => $profile);
            $event = new Event($profile->uuid, 'profiler', $payload, 0.0);
            $graph = self::json((new Mapper())->callGraph($event, threshold: 0));

            Assert::same(Mapper::countType($payload), 'samples');
            Assert::same(self::json((new Mapper())->topFunctions($event, 'wt'))['schema'][1]['description'], 'Samples with the function on the stack');
            Assert::same(\array_column(\array_column($graph['nodes'], 'data'), 'name'), ['main() (2 samples)', 'foo (1 sample)']);
        }
    }

    public function testPprofHeapProfileShowsObjects(): void
    {
        $pprof = new PprofProfile([['inuse_objects', 'count'], ['inuse_space', 'bytes']]);
        $pprof->sample([$pprof->frame('foo'), $pprof->frame('main')], [3, 512])->sample([2], [1, 256]);
        $builder = new PprofProfileBuilder();
        $profile = $builder->createProfile($builder->decode($pprof->encode()));
        $event = new Event($profile->uuid, 'profiler', Payload::new(Type::Pprof, static fn() => $profile), 0.0);

        $top = self::json((new Mapper())->topFunctions($event, 'mu'));
        $graph = self::json((new Mapper())->callGraph($event, threshold: 0, metric: 'mu'));

        Assert::same($top['schema'][1]['description'], 'inuse_objects of the function and its callees');
        Assert::same(
            \array_column(\array_column($graph['nodes'], 'data'), 'name'),
            ['main() (4 inuse_objects)', 'foo (3 inuse_objects)'],
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

    public function testCallGraphKeepsNodePerRecursionLevelByDefault(): void
    {
        $graph = self::json((new Mapper())->callGraph(self::event(), threshold: 0, percentage: 100));

        Assert::same($graph['recursion'], 'levels');
        Assert::same(\array_count_values(\array_column(\array_column($graph['nodes'], 'data'), 'name'))['bar (2x)'] ?? 0, 1);
        Assert::contains(self::edges($graph), 'bar => bar: 1.439%');
    }

    public function testCollapsedCallGraphMergesRecursionLevelsIntoOneNode(): void
    {
        $graph = self::json((new Mapper())->callGraph(self::event(), threshold: 0, percentage: 100, collapseRecursion: true));

        Assert::same($graph['recursion'], 'collapsed');
        $nodes = \array_column(\array_column($graph['nodes'], 'data'), null, 'name');
        // Two calls of `bar` from `foo` and one recursive; the cost is of the outer two, which take the third in
        Assert::same($nodes['bar (3x, 1 recursive)']['metrics'], ['cost' => 37, 'percents' => 26.619]);
        Assert::same(self::edges($graph), [
            'foo => bar: 26.619%',
            'foo => strlen: 2.158%',
            'bar => bar: 1.439%',
            'main() => foo: 74.82%',
            'main() => xhprof_disable: 0.719%',
        ]);
    }

    public function testCollapsedCallGraphCountsMutualRecursionOnce(): void
    {
        // `a()` calls `b()`, which calls `a()` again, twice deep
        $event = self::event([
            'main()==>a' => ['ct' => 1, 'wt' => 100, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'a==>b' => ['ct' => 1, 'wt' => 90, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'b==>a@1' => ['ct' => 1, 'wt' => 80, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'a@1==>b@1' => ['ct' => 1, 'wt' => 70, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'b@1==>a@2' => ['ct' => 1, 'wt' => 60, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'main()' => ['ct' => 1, 'wt' => 100, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
        ]);

        $graph = self::json((new Mapper())->callGraph($event, threshold: 0, percentage: 100, collapseRecursion: true));

        $names = \array_column(\array_column($graph['nodes'], 'data'), 'name');
        Assert::array($names)->sameElementsAs(['main() (1x)', 'a (3x, 2 recursive)', 'b (2x, 1 recursive)']);
        // The nested calls of each pair are inside its outer one: their time is in it already
        Assert::array(self::edges($graph))->sameElementsAs(['main() => a: 100%', 'a => b: 90%', 'b => a: 80%']);
    }

    public function testCollapsedCallGraphOfSamplesCountsSampleOnce(): void
    {
        $profile = (new StacksProfileBuilder())->collapsed("main;f;f;f 2\nmain;f;g 1\nmain 1\n", period: 0.001);
        $event = new Event($profile->uuid, 'profiler', Payload::new(Type::Collapsed, static fn() => $profile), 0.0);

        $levels = self::json((new Mapper())->callGraph($event, threshold: 0, percentage: 100));
        $collapsed = self::json((new Mapper())->callGraph($event, threshold: 0, percentage: 100, collapseRecursion: true));

        Assert::count($levels['nodes'], 5);
        Assert::array(\array_column(\array_column($collapsed['nodes'], 'data'), 'name'))
            ->sameElementsAs(['main() (4 samples)', 'f (3 samples)', 'g (1 sample)']);
        Assert::array(\preg_replace('/ \(\d+ samples?\)/', '', self::edges($collapsed)))
            ->sameElementsAs(['main() => f: 75%', 'f => f: 50%', 'f => g: 25%']);
    }

    public function testCollapsedCallGraphFollowsRecordedTreeThroughOtherFunctions(): void
    {
        // In the second sample `a` calls itself again after `b` called it: that call is no part of an outer `a => a`
        $profile = (new StacksProfileBuilder())->collapsed("main;a;a 1\nmain;a;b;a;a 1\n", period: 0.001);
        $event = new Event($profile->uuid, 'profiler', Payload::new(Type::Collapsed, static fn() => $profile), 0.0);

        $graph = self::json((new Mapper())->callGraph($event, threshold: 0, percentage: 100, collapseRecursion: true));

        Assert::array(\preg_replace('/ \(\d+ samples?\)/', '', self::edges($graph)))
            ->sameElementsAs(['main() => a: 100%', 'a => a: 100%', 'a => b: 50%', 'b => a: 50%']);
    }

    public function testCollapsedCallGraphLinksThroughHiddenFunctions(): void
    {
        // `h` and `k` (10% each) are hidden: `a` calls `b` (20%) through both
        $profile = (new StacksProfileBuilder())->collapsed(
            "main;a;h;b 10\nmain;a;k;b 10\nmain;a 70\nmain 10\n",
            period: 0.001,
        );
        $event = new Event($profile->uuid, 'profiler', Payload::new(Type::Collapsed, static fn() => $profile), 0.0);

        $graph = self::json((new Mapper())->callGraph($event, threshold: 15, percentage: 100, collapseRecursion: true));

        Assert::array(\preg_replace('/ \(\d+ samples?\)/', '', self::edges($graph)))
            ->sameElementsAs(['main() => a: 90%', 'a => b: 20%']);
    }

    public function testCollapsedCallGraphLinksCycleToRecursiveRoot(): void
    {
        // `f` calls itself, and is the root still; `x` and `y` call each other, and `a`, `b`, `c` call `x` under
        // the threshold: one of these calls links the cycle to the root
        $profile = (new StacksProfileBuilder())->collapsed(
            "f;f 10\nf;a 20\nf;b 20\nf;c 20\nf;a;x;y;x 5\nf;b;x;y;x 5\nf;c;x;y;x 5\n",
            period: 0.001,
        );
        $event = new Event($profile->uuid, 'profiler', Payload::new(Type::Collapsed, static fn() => $profile), 0.0);

        $graph = self::json((new Mapper())->callGraph($event, threshold: 10, percentage: 100, collapseRecursion: true));

        $edges = \preg_replace('/ \(\d+ samples?\)|: [\d.]+%/', '', self::edges($graph));
        Assert::contains($edges, 'f => f');
        Assert::count(\array_intersect($edges, ['a => x', 'b => x', 'c => x']), 1);
    }

    public function testCollapsedCallGraphOfSampledSpxCountsCallsOfAllLevels(): void
    {
        // `foo` calls itself once
        $spx = Metadata::fromJson('{"enabled_metrics": ["wt"], "called_function_count": 2, "recorded_call_count": 3}');
        $trace = "[events]\n0 1 0\n1 1 1000\n1 1 1500\n1 0 1800\n1 0 2000\n0 0 3000\n[functions]\nmain\nfoo\n";
        $profile = (new SpxProfileBuilder())->createProfile($spx, [(string) \gzencode($trace)], samplingPeriod: 100);
        $event = new Event($profile->uuid, 'profiler', Payload::new(Type::SPX, static fn() => $profile), 0.0);

        $graph = self::json((new Mapper())->callGraph($event, threshold: 0, collapseRecursion: true));

        Assert::array(\array_column(\array_column($graph['nodes'], 'data'), 'name'))
            ->sameElementsAs(['main() (1 sampled call)', 'foo (2 sampled calls, 1 recursive)']);
    }

    public function testCollapsedCallGraphCountsNestedCallsOfRecordedTree(): void
    {
        // `a` calls itself, and that call calls itself again: two calls of `a => a`, one inside the other
        $profile = (new SpeedscopeProfileBuilder())->createProfile([
            '$schema' => SpeedscopeProfileBuilder::SCHEMA,
            'shared' => ['frames' => [['name' => 'main'], ['name' => 'a']]],
            'profiles' => [[
                'type' => 'evented',
                'unit' => 'milliseconds',
                'startValue' => 0,
                'endValue' => 10,
                'events' => [
                    ['type' => 'O', 'at' => 0, 'frame' => 0],
                    ['type' => 'O', 'at' => 0, 'frame' => 1],
                    ['type' => 'O', 'at' => 1, 'frame' => 1],
                    ['type' => 'O', 'at' => 2, 'frame' => 1],
                    ['type' => 'C', 'at' => 3, 'frame' => 1],
                    ['type' => 'C', 'at' => 4, 'frame' => 1],
                    ['type' => 'C', 'at' => 5, 'frame' => 1],
                    ['type' => 'C', 'at' => 10, 'frame' => 0],
                ],
            ]],
        ]);
        $event = new Event($profile->uuid, 'profiler', Payload::new(Type::Speedscope, static fn() => $profile), 0.0);

        $wt = self::json((new Mapper())->callGraph($event, threshold: 0, percentage: 100, collapseRecursion: true));
        $ct = self::json((new Mapper())->callGraph($event, threshold: 0, percentage: 100, metric: 'ct', collapseRecursion: true));

        Assert::array(\array_column(\array_column($wt['nodes'], 'data'), 'name'))
            ->sameElementsAs(['main() (1x)', 'a (3x, 2 recursive)']);
        // The time of the inner call is in the outer one; both calls count
        Assert::array(self::edges($wt))->sameElementsAs(['main() => a: 50%', 'a => a: 30%']);
        Assert::array(self::edges($ct))->sameElementsAs(['main() => a: 100%', 'a => a: 200%']);
    }

    public function testCollapsedCallGraphTakesCostOfOutermostLevelInAnyOrder(): void
    {
        // The deeper level of `a` comes first in the payload
        $event = self::event([
            'x==>a@1' => ['ct' => 1, 'wt' => 10, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'a==>x' => ['ct' => 1, 'wt' => 30, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'main()==>a' => ['ct' => 1, 'wt' => 50, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'main()' => ['ct' => 1, 'wt' => 100, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
        ]);

        $graph = self::json((new Mapper())->callGraph($event, threshold: 0, percentage: 100, collapseRecursion: true));

        $nodes = \array_column(\array_column($graph['nodes'], 'data'), null, 'name');
        Assert::same($nodes['a (2x, 1 recursive)']['metrics'], ['cost' => 50, 'percents' => 50]);
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

    public function testCallGraphHidesCallsUnderThreshold(): void
    {
        $graph = self::json((new Mapper())->callGraph(self::event(self::SHARED_STUB), threshold: 15, percentage: 100));

        Assert::array(self::edges($graph))->sameElementsAs(['main() => a: 20%', 'main() => b: 40%', 'b => x: 30%']);
    }

    public function testCallGraphKeepsHeaviestCallUnderThreshold(): void
    {
        $graph = self::json((new Mapper())->callGraph(self::event(self::SHARED_STUB), threshold: 35, percentage: 100));

        // `main() => x` through the hidden `a` takes 10%
        Assert::array(self::edges($graph))->sameElementsAs(['main() => b: 40%', 'b => x: 30%']);
    }

    public function testTopFunctionsSumCallsFromAllCallers(): void
    {
        $top = self::json((new Mapper())->topFunctions(self::event(self::SHARED_STUB), 'wt'));
        $x = self::find($top['functions'], 'x');

        Assert::same([$x['ct'], $x['wt'], $x['excl_wt']], [2, 40, 35]);
        Assert::count($top['functions'], 5);
    }

    public function testTopFunctionsSaturateExclusiveCostsPastIntegerRange(): void
    {
        $cost = static fn(int $value): array => ['ct' => 1, 'wt' => $value, 'cpu' => $value, 'mu' => $value, 'pmu' => $value];
        // Negative callees of received data add to the own cost: `f` and `f@1` have 5e18 each over its maximum
        $event = self::event([
            'main()' => $cost(\PHP_INT_MAX),
            'main()==>f' => $cost(\PHP_INT_MAX),
            'f==>f@1' => $cost(1),
            'f==>g' => $cost(-5_000_000_000_000_000_000),
            'f@1==>h' => $cost(-5_000_000_000_000_000_000),
        ]);

        $f = self::find(self::json((new Mapper())->topFunctions($event, 'wt'))['functions'], 'f');

        Assert::same(
            [$f['excl_wt'], $f['excl_cpu'], $f['excl_mu'], $f['excl_pmu']],
            [\PHP_INT_MAX, \PHP_INT_MAX, \PHP_INT_MAX, \PHP_INT_MAX],
        );
    }

    public function testTopFunctionsSumSignedCalleesInAnyOrder(): void
    {
        $memory = static fn(int $mu): array => ['ct' => 1, 'wt' => 0, 'cpu' => 0, 'mu' => $mu, 'pmu' => 0];
        // The callees free and allocate past the integer range, and cancel out
        $event = self::event([
            'main()==>f' => $memory(5),
            'f==>g1' => $memory(-\PHP_INT_MAX),
            'f==>g2' => $memory(-\PHP_INT_MAX),
            'f==>g3' => $memory(\PHP_INT_MAX),
            'f==>g4' => $memory(\PHP_INT_MAX),
        ]);

        $f = self::find(self::json((new Mapper())->topFunctions($event, 'mu'))['functions'], 'f');

        Assert::same($f['excl_mu'], 5);
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

    public function testFlameChartTakesWidthsOfMetric(): void
    {
        $chart = self::json((new Mapper())->flameChart(self::event(), 'mu'));

        Assert::same($chart[0]['duration'], 5936 / 1024);
        Assert::same(\array_column($chart[0]['children'], 'duration'), [4168 / 1024, 344 / 1024]);
    }

    public function testComparisonPutsFunctionsThatChangedMostFirst(): void
    {
        $compare = self::XHPROF_STUB + ['main()==>baz' => ['ct' => 1, 'wt' => 50, 'cpu' => 0, 'mu' => 100, 'pmu' => 0]];

        $functions = self::json((new Mapper())->compare(self::event(), self::event($compare)))['functions'];

        Assert::same($functions[0], [
            'function' => 'baz',
            'base_ct' => 0,
            'compare_ct' => 1,
            'diff_ct' => 1,
            'base_excl_wt' => 0,
            'compare_excl_wt' => 50,
            'diff_excl_wt' => 50,
            'base_excl_mu' => 0,
            'compare_excl_mu' => 100,
            'diff_excl_mu' => 100,
        ]);
        Assert::same($functions[1]['function'], 'main()');
        // Its own time drops by the time of the new callee, not below zero
        Assert::same($functions[1]['diff_excl_wt'], -34);
        Assert::same(\array_column(\array_slice($functions, 2), 'diff_excl_wt'), [0, 0, 0, 0]);
    }

    public function testCallGraphLinksThroughHiddenFunctionsByExactTree(): void
    {
        $graph = self::json((new Mapper())->callGraph(self::xdebugEvent(self::BYPASS_CACHEGRIND), threshold: 8, percentage: 100));

        Assert::same(self::edges($graph), [
            'main => a: 10%',
            'main => b: 10%',
            'main => c: 10%',
            // Both calls of `z` take 6%: the first one stays as the heaviest
            'a => z: 6%',
        ]);
    }

    public function testCallGraphLinksThroughHiddenFunctionsByUnfoldedGraph(): void
    {
        $graph = self::json((new Mapper())->callGraph(self::event(self::BYPASS_XHPROF), threshold: 8, percentage: 100));

        // XHProf sums the calls of `j` from both callers: `a => z` and `b => z` are estimated as 5% and 1%
        Assert::same(self::edges($graph), [
            'main() => a: 10%',
            'main() => b: 10%',
            'main() => c: 10%',
            'c => z: 6%',
        ]);
    }

    public function testCallGraphLinksFunctionsCallingEachOtherToRoot(): void
    {
        $graph = self::json((new Mapper())->callGraph(self::xdebugEvent(self::ISLAND_CACHEGRIND), threshold: 3, percentage: 100, metric: 'mu'));

        // The heaviest calls of `A` and `B` come from each other: the heaviest call from `{main}` links them to it
        Assert::array(self::edges($graph))->sameElementsAs(['A => B: 100%', 'B => A: 100%', '{main} => B: 2%']);
    }

    public function testCallGraphSaturatesCallsThroughHiddenFunctionsPastIntegerRange(): void
    {
        $cost = static fn(int $value): array => ['ct' => $value, 'wt' => $value, 'cpu' => $value, 'mu' => $value, 'pmu' => $value];
        // `h1` and `h2` are hidden: `a => f` sums its direct call and both calls through them
        $event = self::event([
            'main()' => $cost(\PHP_INT_MAX),
            'main()==>a' => $cost(\PHP_INT_MAX),
            'a==>h1' => $cost(\intdiv(\PHP_INT_MAX, 200)),
            'a==>h2' => $cost(\intdiv(\PHP_INT_MAX, 200)),
            'h1==>f' => $cost(5_000_000_000_000_000_000),
            'h2==>f' => $cost(5_000_000_000_000_000_000),
            'a==>f' => $cost(5_000_000_000_000_000_000),
        ]);

        $graph = self::json((new Mapper())->callGraph($event));

        Assert::contains(self::edges($graph), 'a => f: 100%');
    }

    public function testFacetsAreHostnameAndTags(): void
    {
        $profile = new Profile(metadata: ['hostname' => 'web-1'], tags: ['env' => 'staging']);

        $event = (new Mapper())->map(new ProfilerFrame(Payload::new(Type::XHProf, static fn() => $profile)));

        Assert::same($event->facets, ['host' => ['web-1'], 'tag:env' => ['staging']]);
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

    private static function xdebugEvent(string $cachegrind): Event
    {
        $profile = (new XDebugProfileBuilder())->createProfile($cachegrind);

        return new Event(
            uuid: $profile->uuid,
            type: 'profiler',
            payload: Payload::new(Type::XDebug, static fn() => $profile),
            timestamp: 0.0,
        );
    }

    /**
     * @return list<string> `caller => callee: label`, node names without the call counts.
     */
    private static function edges(array $graph): array
    {
        $names = \array_map(
            static fn(string $name): string => \preg_replace('/ \(\d+x(?:, \d+ recursive)?\)$/', '', $name),
            \array_column(\array_column($graph['nodes'], 'data'), 'name', 'id'),
        );

        return \array_map(
            static fn(array $e): string => "{$names[$e['data']['source']]} => {$names[$e['data']['target']]}: {$e['data']['label']}",
            $graph['edges'],
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
