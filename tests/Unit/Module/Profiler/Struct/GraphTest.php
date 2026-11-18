<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Struct;

use Buggregator\Trap\Module\Profiler\Struct\Branch;
use Buggregator\Trap\Module\Profiler\Struct\Cost;
use Buggregator\Trap\Module\Profiler\Struct\Edge;
use Buggregator\Trap\Module\Profiler\Struct\Graph;
use Buggregator\Trap\Module\Profiler\Struct\Metric;
use Buggregator\Trap\Module\Profiler\Struct\Peaks;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Module\Profiler\Struct\Tree;
use Buggregator\Trap\Module\Profiler\XHProf\ProfileBuilder;
use Buggregator\Trap\Support\TimeSlice;
use Buggregator\Trap\Tests\Unit\Module\Profiler\Mcp\Stub\Profiles;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Covers(Graph::class)]
#[Covers(Profile::class)]
#[Test]
final class GraphTest
{
    /**
     * `x()` is called from both `a()` and `b()` and calls `y()`.
     */
    private const SHARED = [
        'x==>y' => ['ct' => 4, 'wt' => 8, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
        'a==>x' => ['ct' => 1, 'wt' => 10, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
        'main()==>a' => ['ct' => 1, 'wt' => 20, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
        'b==>x' => ['ct' => 1, 'wt' => 30, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
        'main()==>b' => ['ct' => 1, 'wt' => 40, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
        'main()' => ['ct' => 1, 'wt' => 100, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
    ];

    public function testUnfoldKeepsCostsOfSingleCallerFunctions(): void
    {
        $tree = self::graph([
            'main()==>a' => ['ct' => 2, 'wt' => 60, 'cpu' => 30, 'mu' => 10, 'pmu' => 20],
            'main()' => ['ct' => 1, 'wt' => 100, 'cpu' => 50, 'mu' => 40, 'pmu' => 80],
        ])->unfold();

        Assert::same(self::shape($tree), ['main():100' => ['a:60' => []]]);
        $a = $tree->all['n2']->item->cost;
        Assert::same([$a->ct, $a->wt, $a->cpu, $a->mu, $a->pmu], [2, 60, 30, 10, 20]);
        Assert::same($a->p_wt, 60.0);
    }

    public function testUnfoldScalesSharedCalleeByCaller(): void
    {
        $tree = self::graph(self::SHARED)->unfold();

        Assert::same(self::shape($tree), [
            'main():100' => [
                'a:20' => ['x:10' => ['y:2' => []]],
                'b:40' => ['x:30' => ['y:6' => []]],
            ],
        ]);
        $yUnderA = $tree->all['n4']->item;
        Assert::same([$yUnderA->callee, $yUnderA->cost->ct, $yUnderA->cost->p_wt], ['y', 1, 2.0]);
    }

    public function testUnfoldComputesDeltaAgainstTreeParent(): void
    {
        $tree = self::graph(self::SHARED)->unfold();

        Assert::same($tree->all['n4']->item->cost->d_wt, -8);
        Assert::same($tree->all['n1']->item->cost->d_wt, 0);
    }

    public function testUnfoldDoesNotChangeGraphEdges(): void
    {
        $graph = self::graph(self::SHARED);
        $graph->unfold();

        $y = $graph->callers('y')[0]->cost;
        Assert::same([$y->wt, $y->p_wt, $y->d_wt], [8, 8.0, -32]);
    }

    public function testUnfoldDropsNodesBelowMinShare(): void
    {
        $tree = self::graph(self::SHARED)->unfold(minShare: 0.05);

        Assert::same(self::shape($tree), [
            'main():100' => [
                'a:20' => ['x:10' => []],
                'b:40' => ['x:30' => ['y:6' => []]],
            ],
        ]);
    }

    public function testUnfoldStopsOnCycle(): void
    {
        $tree = self::graph([
            'a==>b' => ['ct' => 1, 'wt' => 5, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'b==>a' => ['ct' => 1, 'wt' => 3, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'main()==>a' => ['ct' => 1, 'wt' => 10, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'main()' => ['ct' => 1, 'wt' => 10, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
        ])->unfold();

        Assert::same(\count($tree), 3);
    }

    public function testUnfoldStopsAtMaxNodes(): void
    {
        $graph = self::graph(Profiles::lattice(levels: 12, chain: 100));

        $tree = $graph->unfold(minShare: 0.0001, maxNodes: 1000);

        Assert::int(\count($tree))->greaterThan(0)->lessThanOrEqual(1000);
        Assert::true($tree->cut);
        Assert::false(self::graph(self::SHARED)->unfold(maxNodes: 7)->cut);
    }

    public function testCutTreeKeepsCallsInCallOrder(): void
    {
        $tree = self::graph(self::SHARED)->unfold(maxNodes: 4);

        Assert::true($tree->cut);
        $names = \array_map(static fn(Branch $branch): string => $branch->item->callee, \array_values($tree->all));
        Assert::same($names, ['main()', 'a', 'x', 'y']);
    }

    public function testCutKeepsMemoryAndWorkBounded(): void
    {
        // 6 000 leaves under the last level: a body of 344 KB unfolding into about 2^14 * 3 000 nodes
        $graph = self::graph(Profiles::lattice(levels: 13, chain: 30, leaves: 3000));
        \gc_collect_cycles();
        $before = \memory_get_usage();
        \memory_reset_peak_usage();

        [$tree, $ticks] = self::unfoldTicking($graph, 0.0001, 50_000, 3 * 50_000);

        $peak = \memory_get_peak_usage() - $before;
        Assert::true($tree->cut);
        Assert::count($tree, 50_000);
        Assert::true($peak < 96 * 1024 * 1024, \sprintf('Peak memory grew by %.1f MB.', $peak / 1048576));
        Assert::int($ticks)->lessThanOrEqual(2 * 50_000 + 1);
    }

    public function testUnfoldSkipsLightCalleesOfLargeFanOut(): void
    {
        // Each of the 256 nodes of the last level calls 5 000 leaves too light to show
        $graph = self::graph(Profiles::lattice(levels: 8, chain: 0, leaves: 5000));
        $expected = $graph->unfold(0.0001);

        [$tree, $ticks, $maxGrowth] = self::unfoldTicking($graph, 0.0001, \PHP_INT_MAX, 10 * \count($expected));

        Assert::same(self::shape($tree), self::shape($expected));
        Assert::same(\array_keys($tree->all), \array_keys($expected->all));
        // Every entry of the stack is a tick: a node, the end of its subtree, a skipped call
        Assert::int($ticks)->lessThanOrEqual(2 * \count($tree));
        // The leaves of a function are sorted once, about 0.3 MB; stacking them all takes over 1 MB a node
        Assert::int($maxGrowth)->lessThan(512 * 1024);
    }

    public function testUnfoldKeepsCalleesAsCheckedOneByOne(): void
    {
        $h = 4_000_000_000_000_000_000;
        $calls = [
            'main()' => ['ct' => 1, 'wt' => $h, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'main()==>neg' => ['ct' => 1, 'wt' => 1, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'main()==>f1' => ['ct' => 1, 'wt' => $h, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            // Equal values, and exactly at the threshold of a quarter
            'main()==>a' => ['ct' => 1, 'wt' => $h / 4, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'main()==>b' => ['ct' => 1, 'wt' => $h / 4, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
        ];
        // The other caller brings the inclusive time of each `f` to 1: the scale grows by `$h` a level till INF
        for ($i = 1; $i <= 20; ++$i) {
            $calls['neg==>f' . $i] = ['ct' => 1, 'wt' => 1 - $h, 'cpu' => 0, 'mu' => 0, 'pmu' => 0];
            $calls["f$i==>f" . ($i + 1)] = ['ct' => 1, 'wt' => $h, 'cpu' => 0, 'mu' => 0, 'pmu' => 0];
            $calls["f$i==>z$i"] = ['ct' => 1, 'wt' => 0, 'cpu' => 0, 'mu' => 0, 'pmu' => 0];
        }
        $graph = self::graph($calls);

        $tree = $graph->unfold(minShare: 0.25);

        $expected = self::unfoldOneByOne($graph, 0.25);
        Assert::contains($expected, 'n1:a');
        Assert::contains($expected, 'n1:b');
        // A zero time at an INF scale is NaN: not under the threshold
        Assert::array(\preg_grep('/:z\d+$/', $expected))->hasCount(4);
        Assert::same(
            \array_map(static fn(Branch $branch): string => "{$branch->parentId}:{$branch->item->calleeId}", $tree->all),
            $expected,
        );
    }

    public function testUnfoldKeepsCalleesAtNegativeScale(): void
    {
        // Negative call counts of received data make a negative scale, which reverses the order of the values
        $zero = ['wt' => 0, 'cpu' => 0, 'mu' => 0, 'pmu' => 0];
        $graph = self::graph([
            'A' => ['ct' => 0] + $zero,
            'A==>q' => ['ct' => -1000] + $zero,
            'A==>c2' => ['ct' => 100] + $zero,
            'A==>c4' => ['ct' => 50] + $zero,
            'A==>c5' => ['ct' => 20] + $zero,
            'r==>A' => ['ct' => 5] + $zero,
            'B' => ['ct' => 0] + $zero,
            'B==>r' => ['ct' => -10_000_000] + $zero,
        ]);

        $tree = $graph->unfold(0.0001);

        Assert::contains(
            \array_map(static fn(Branch $branch): string => "{$branch->parentId}:{$branch->item->calleeId}", $tree->all),
            'n1:q',
        );
    }

    public function testUnfoldPrunesByCallsWithoutWallTime(): void
    {
        // Unpruned, 36 levels would unfold into 2^37 nodes
        $graph = self::graph(Profiles::lattice(levels: 36, chain: 0, wt: 0));

        $tree = $graph->unfold(minShare: 0.001, maxNodes: 100_000);

        Assert::false($tree->cut);
        Assert::int(\count($tree))->lessThan(10_000);
    }

    public function testUnfoldPrunesByCallsOfRootAddedWithoutMain(): void
    {
        $calls = Profiles::lattice(levels: 36, chain: 0, wt: 0);
        unset($calls['main()']);
        $graph = self::graph($calls);

        $tree = $graph->unfold(minShare: 0.001, maxNodes: 100_000);

        Assert::false($tree->cut);
        Assert::int(\count($tree))->lessThan(10_000);
        Assert::same($tree->all['n1']->item->cost->ct, 0);
    }

    public function testUnfoldLetsOtherFibersRun(): void
    {
        $graph = self::graph(Profiles::lattice(levels: 12, chain: 10));
        $fiber = new \Fiber(static fn(): Tree => $graph->unfold(0.0001, 5_000, new TimeSlice(0.0, 1)));

        $suspensions = 0;
        $fiber->start();
        while (!$fiber->isTerminated()) {
            ++$suspensions;
            $fiber->resume();
        }

        Assert::int($suspensions)->greaterThan(0);
        Assert::same(\count($fiber->getReturn()), \count($graph->unfold(0.0001, 5_000)));
    }

    public function testHotPathFollowsHeaviestCalls(): void
    {
        $path = self::graph(self::SHARED)->hotPath(Metric::WallTime);

        Assert::same(\array_map(static fn(Edge $edge): string => $edge->callee, $path), ['main()', 'b', 'x', 'y']);
    }

    public function testHotPathSkipsCallsWithoutTheMetric(): void
    {
        $graph = self::graph(self::SHARED);

        Assert::same($graph->hotPath(Metric::CpuTime), []);
        Assert::count($graph->hotPath(Metric::Count), 4);
    }

    public function testHotPathStopsOnCycle(): void
    {
        $path = self::graph([
            'a==>b' => ['ct' => 1, 'wt' => 5, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'b==>a' => ['ct' => 1, 'wt' => 3, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'main()==>a' => ['ct' => 1, 'wt' => 10, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'main()' => ['ct' => 1, 'wt' => 10, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
        ])->hotPath(Metric::WallTime);

        Assert::same(\array_map(static fn(Edge $edge): string => $edge->callee, $path), ['main()', 'a', 'b']);
    }

    public function testProfileTreeIsUnfoldedFromGraph(): void
    {
        $profile = (new ProfileBuilder())->createProfile(calls: self::SHARED);

        Assert::same(\count($profile->tree()), 7);
        Assert::same($profile->tree(), $profile->tree());
        Assert::false($profile->exactTree);
    }

    public function testProfileTreeIsUnfoldedOnceByFibers(): void
    {
        $profile = (new ProfileBuilder())->createProfile(calls: Profiles::lattice(levels: 4, chain: 5));
        $unfold = static fn(): Tree => $profile->tree(new TimeSlice(0.0, 1));
        $fibers = [new \Fiber($unfold), new \Fiber($unfold)];

        foreach ($fibers as $fiber) {
            $fiber->start();
        }
        while (!$fibers[0]->isTerminated() || !$fibers[1]->isTerminated()) {
            foreach ($fibers as $fiber) {
                $fiber->isTerminated() or $fiber->resume();
            }
        }

        Assert::same($fibers[0]->getReturn(), $fibers[1]->getReturn());
    }

    public function testProfileTreeOutsideFiberDoesNotWaitForFiber(): void
    {
        $profile = (new ProfileBuilder())->createProfile(calls: Profiles::lattice(levels: 4, chain: 5));
        $fiber = new \Fiber(static fn(): Tree => $profile->tree(new TimeSlice(0.0, 1)));
        $fiber->start();

        $tree = $profile->tree();

        while (!$fiber->isTerminated()) {
            $fiber->resume();
        }
        Assert::same($fiber->getReturn(), $tree);
        Assert::same($profile->tree(), $tree);
    }

    public function testProfileTreePrefersExactTree(): void
    {
        /** @var Tree<Edge> $tree */
        $tree = new Tree();
        $profile = new Profile(tree: $tree);

        Assert::same($profile->tree(), $tree);
        Assert::true($profile->exactTree);
    }

    public function testFromTreeSumsNodesByCallerAndCallee(): void
    {
        /** @var Tree<Edge> $tree */
        $tree = new Tree();
        $tree->addItem(new Edge(null, 'main', new Cost(1, 100, 10, 50, 60)), 'n1', null);
        $tree->addItem(new Edge('main', 'a', new Cost(1, 30, 3, 10, 0)), 'n2', 'n1');
        $tree->addItem(new Edge('a', 'x', new Cost(1, 5, 1, 2, 0)), 'n3', 'n2');
        $tree->addItem(new Edge('main', 'a', new Cost(1, 40, 4, 20, 0)), 'n4', 'n1');
        $tree->addItem(new Edge('a', 'x', new Cost(2, 7, 2, 3, 0)), 'n5', 'n4');

        $graph = Graph::fromTree($tree);

        Assert::same(\count($graph), 3);
        $a = $graph->callers('a')[0]->cost;
        Assert::same([$a->ct, $a->wt, $a->cpu, $a->mu], [2, 70, 7, 30]);
        $x = $graph->callers('x')[0]->cost;
        Assert::same([$x->ct, $x->wt], [3, 12]);
        Assert::same(\array_map(static fn(Edge $e): string => $e->calleeId, $graph->callees('main')), ['a']);
    }

    public function testFromTreeSaturatesSumsPastIntegerRange(): void
    {
        /** @var Tree<Edge> $tree */
        $tree = new Tree();
        $tree->addItem(new Edge(null, 'main', new Cost(1, \PHP_INT_MAX, \PHP_INT_MAX, 0, 0)), 'n1', null);
        $tree->addItem(new Edge('main', 'a', new Cost(1, \PHP_INT_MAX, \PHP_INT_MAX, 0, 0)), 'n2', 'n1');
        $tree->addItem(new Edge('main', 'a', new Cost(1, 1, 1, 0, 0)), 'n3', 'n1');

        $a = Graph::fromTree($tree)->callers('a')[0]->cost;

        Assert::same([$a->ct, $a->wt, $a->cpu], [2, \PHP_INT_MAX, \PHP_INT_MAX]);
    }

    public function testSetsPercentsAndDeltasOfEdges(): void
    {
        $graph = new Graph();
        $graph->add($main = new Edge(null, 'main', new Cost(1, 100, 0, 0, 0)));
        $graph->add($a = new Edge('main', 'a', new Cost(1, 25, 0, 0, 0)));

        $graph->setPercentsAndDeltas(new Peaks(1, 100, 0, 0, 0));

        Assert::same([$a->cost->p_wt, $a->cost->d_wt], [25.0, -75]);
        Assert::same([$main->cost->p_wt, $main->cost->d_wt], [100.0, 0]);
    }

    public function testInclusiveCostSaturatesPastIntegerRange(): void
    {
        $graph = new Graph();
        $graph->add(new Edge('a', 'f', new Cost(-\PHP_INT_MAX, 5_000_000_000_000_000_000, 1, \PHP_INT_MAX, 0)));
        $graph->add(new Edge('b', 'f', new Cost(-\PHP_INT_MAX, 5_000_000_000_000_000_000, \PHP_INT_MAX, 1, 0)));

        $f = $graph->inclusiveCost('f');

        Assert::same([$f->ct, $f->wt, $f->cpu, $f->mu], [\PHP_INT_MIN, \PHP_INT_MAX, \PHP_INT_MAX, \PHP_INT_MAX]);
    }

    public function testProfileWithCostsPastIntegerRangeUnfolds(): void
    {
        $graph = self::graph([
            'main()' => ['ct' => 1, 'wt' => \PHP_INT_MAX],
            'main()==>a' => ['ct' => 1, 'wt' => 5_000_000_000_000_000_000],
            'main()==>b' => ['ct' => 1, 'wt' => 5_000_000_000_000_000_000],
            'a==>f' => ['ct' => 1, 'wt' => 5_000_000_000_000_000_000],
            'b==>f' => ['ct' => 1, 'wt' => 5_000_000_000_000_000_000],
        ]);

        $tree = $graph->unfold();

        Assert::same($graph->inclusiveCost('f')->wt, \PHP_INT_MAX);
        Assert::same(\count($tree), 5);
    }

    public function testRootAddedWithoutMainSaturatesPastIntegerRange(): void
    {
        $graph = self::graph([
            'a==>f' => ['ct' => 1, 'wt' => 5_000_000_000_000_000_000, 'cpu' => 5_000_000_000_000_000_000],
            'a==>g' => ['ct' => 1, 'wt' => 5_000_000_000_000_000_000, 'cpu' => 5_000_000_000_000_000_000],
        ]);

        $a = $graph->roots()[0]->cost;

        Assert::same([$a->wt, $a->cpu], [\PHP_INT_MAX, \PHP_INT_MAX]);
    }

    public function testDeltasSaturatePastIntegerRange(): void
    {
        $graph = self::graph([
            'main()==>a' => ['ct' => 1, 'wt' => -5_000_000_000_000_000_000],
            'b==>a' => ['ct' => 1, 'wt' => -5_000_000_000_000_000_000],
            'a==>f' => ['ct' => 1, 'wt' => 1],
        ]);

        $f = $graph->callers('f')[0]->cost;

        Assert::same($f->d_wt, \PHP_INT_MAX);
    }

    public function testFunctionIdsOfDigitsAreStrings(): void
    {
        $graph = self::graph([
            '10==>20' => ['ct' => 1, 'wt' => 5, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'main()==>10' => ['ct' => 1, 'wt' => 10, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
            'main()' => ['ct' => 1, 'wt' => 100, 'cpu' => 0, 'mu' => 0, 'pmu' => 0],
        ]);

        Assert::same($graph->functions(), ['20', '10', 'main()']);
    }

    public function testMissingRootOfDigitsIsAdded(): void
    {
        $graph = self::graph(['10==>a' => ['ct' => 1, 'wt' => 5, 'cpu' => 0, 'mu' => 0, 'pmu' => 0]]);

        Assert::same(\array_map(static fn(Edge $e): string => $e->calleeId, $graph->roots()), ['10']);
    }

    public function testAddingDuplicateEdgeFails(): void
    {
        $graph = new Graph();
        $graph->add(new Edge('a', 'b', new Cost(1, 1, 1, 1, 1)));

        Expect::exception(\LogicException::class);
        $graph->add(new Edge('a', 'b', new Cost(1, 1, 1, 1, 1)));
    }

    /**
     * Unfolds in a fiber suspended at every tick: the ticks count the work, deterministically on any machine.
     *
     * @param int<1, max> $maxNodes
     * @param positive-int $maxTicks Fails past them rather than run the unbounded work to the end.
     *
     * @return array{Tree<Edge>, int, int} The tree, the ticks and the most memory a tick allocated, bytes.
     */
    private static function unfoldTicking(Graph $graph, float $minShare, int $maxNodes, int $maxTicks): array
    {
        $fiber = new \Fiber(static fn(): Tree => $graph->unfold($minShare, $maxNodes, new TimeSlice(0.0, 1)));
        $ticks = $maxGrowth = 0;
        $memory = \memory_get_usage();
        $fiber->start();
        while (!$fiber->isTerminated()) {
            $maxGrowth = \max($maxGrowth, \memory_get_usage() - $memory);
            ++$ticks <= $maxTicks or Assert::fail("Unfolding took more than $maxTicks ticks.");
            $memory = \memory_get_usage();
            $fiber->resume();
        }

        return [$fiber->getReturn(), $ticks, $maxGrowth];
    }

    /**
     * {@see Graph::unfold()} by wall time without its shortcuts: every callee is checked against the threshold.
     *
     * @return array<non-empty-string, non-empty-string> `parentId:calleeId` by node IDs.
     */
    private static function unfoldOneByOne(Graph $graph, float $minShare): array
    {
        $total = 0;
        foreach ($graph->roots() as $edge) {
            $total += $edge->cost->wt;
        }
        $minValue = (float) $total * $minShare;

        $nodes = [];
        $stack = \array_map(static fn(Edge $edge): array => [$edge, '', 1.0, []], \array_reverse($graph->roots()));
        while ($stack !== []) {
            [$edge, $parent, $scale, $path] = \array_pop($stack);
            $value = (float) Metric::WallTime->of($edge->cost) * $scale;
            if ($value < $minValue || isset($path[$edge->calleeId])) {
                continue;
            }

            $id = 'n' . (\count($nodes) + 1);
            $nodes[$id] = "$parent:{$edge->calleeId}";
            $calleeTotal = Metric::WallTime->of($graph->inclusiveCost($edge->calleeId));
            $childScale = $calleeTotal > 0 ? $value / (float) $calleeTotal : $scale;
            foreach (\array_reverse($graph->callees($edge->calleeId)) as $callee) {
                $stack[] = [$callee, $id, $childScale, $path + [$edge->calleeId => true]];
            }
        }

        return $nodes;
    }

    private static function graph(array $calls): Graph
    {
        return (new ProfileBuilder())->createProfile(calls: $calls)->calls;
    }

    /**
     * @param Tree<Edge> $tree
     *
     * @return array<string, array> Nested `callee:wt` keys.
     */
    private static function shape(Tree $tree): array
    {
        $map = static function (array $branches) use (&$map): array {
            $result = [];
            /** @var Branch<Edge> $branch */
            foreach ($branches as $branch) {
                $result[$branch->item->callee . ':' . $branch->item->cost->wt] = $map($branch->children);
            }
            return $result;
        };

        return $map($tree->root);
    }
}
