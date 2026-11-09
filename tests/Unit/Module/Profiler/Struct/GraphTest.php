<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Struct;

use Buggregator\Trap\Module\Profiler\Struct\Branch;
use Buggregator\Trap\Module\Profiler\Struct\Cost;
use Buggregator\Trap\Module\Profiler\Struct\Edge;
use Buggregator\Trap\Module\Profiler\Struct\Graph;
use Buggregator\Trap\Module\Profiler\Struct\Peaks;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Module\Profiler\Struct\Tree;
use Buggregator\Trap\Module\Profiler\XHProf\ProfileBuilder;
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

    public function testProfileTreeIsUnfoldedFromGraph(): void
    {
        $profile = (new ProfileBuilder())->createProfile(calls: self::SHARED);

        Assert::same(\count($profile->tree()), 7);
        Assert::same($profile->tree(), $profile->tree());
    }

    public function testProfileTreePrefersExactTree(): void
    {
        /** @var Tree<Edge> $tree */
        $tree = new Tree();
        $profile = new Profile(tree: $tree);

        Assert::same($profile->tree(), $tree);
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

    public function testSetsPercentsAndDeltasOfEdges(): void
    {
        $graph = new Graph();
        $graph->add($main = new Edge(null, 'main', new Cost(1, 100, 0, 0, 0)));
        $graph->add($a = new Edge('main', 'a', new Cost(1, 25, 0, 0, 0)));

        $graph->setPercentsAndDeltas(new Peaks(1, 100, 0, 0, 0));

        Assert::same([$a->cost->p_wt, $a->cost->d_wt], [25.0, -75]);
        Assert::same([$main->cost->p_wt, $main->cost->d_wt], [100.0, 0]);
    }

    public function testAddingDuplicateEdgeFails(): void
    {
        $graph = new Graph();
        $graph->add(new Edge('a', 'b', new Cost(1, 1, 1, 1, 1)));

        Expect::exception(\LogicException::class);
        $graph->add(new Edge('a', 'b', new Cost(1, 1, 1, 1, 1)));
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
