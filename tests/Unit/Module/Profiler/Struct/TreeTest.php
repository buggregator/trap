<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Struct;

use Buggregator\Trap\Module\Profiler\Struct\Branch;
use Buggregator\Trap\Module\Profiler\Struct\Cost;
use Buggregator\Trap\Module\Profiler\Struct\Edge;
use Buggregator\Trap\Module\Profiler\Struct\Tree;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Covers(Tree::class)]
#[Test]
final class TreeTest
{
    public static function topNumbers(): iterable
    {
        yield [1];
        yield [5];
        yield [10];
        yield [100];
        yield [1000];
    }

    public function testFromEdgesListBuildsHierarchy(): void
    {
        $tree = Tree::fromEdgesList(
            [self::node('a'), self::node('b', 'a'), self::node('c', 'a'), self::node('d', 'b')],
            static fn(\stdClass $edge): string => $edge->id,
            static fn(\stdClass $edge): ?string => $edge->parent,
        );

        Assert::same(\array_keys($tree->root), ['a']);
        Assert::same(\array_keys($tree->all), ['a', 'b', 'c', 'd']);
        Assert::same($tree->lostChildren, []);
        Assert::same(self::ids($tree->all['a']->children), ['b', 'c']);
        Assert::same(self::ids($tree->all['b']->children), ['d']);
        Assert::same($tree->all['d']->parent, $tree->all['b']);
        Assert::same($tree->all['a']->parent, null);
        Assert::same(\count($tree), 4);
    }

    public function testEmptyEdgesListBuildsEmptyTree(): void
    {
        $tree = Tree::fromEdgesList([], static fn(): string => 'x', static fn(): ?string => null);

        Assert::same(\count($tree), 0);
        Assert::same(\iterator_to_array($tree->getItemsSortedV0(null)), []);
        Assert::same(\iterator_to_array($tree->getItemsSortedV1(null)), []);
    }

    public function testChildAddedBeforeParentIsLostUntilParentArrives(): void
    {
        $tree = new Tree();
        $child = self::node('c', 'p');
        $tree->addItem($child, 'c', 'p');

        Assert::same(self::ids($tree->lostChildren), ['c']);
        Assert::same(self::ids($tree->iterateLostChildren()), ['c']);

        $tree->addItem(self::node('p'), 'p', null);

        Assert::same($tree->lostChildren, []);
        Assert::same(\iterator_to_array($tree->iterateLostChildren()), []);
        Assert::same(self::ids($tree->all['p']->children), ['c']);
    }

    public function testAdoptedLostChildReferencesItsParent(): void
    {
        $tree = new Tree();
        $tree->addItem(self::node('c', 'p'), 'c', 'p');

        $tree->addItem(self::node('p'), 'p', null);

        Assert::same($tree->all['c']->parent, $tree->all['p']);
    }

    public function testChildWithoutParentStaysLost(): void
    {
        $tree = new Tree();
        $tree->addItem(self::node('root'), 'root', null);
        $tree->addItem(self::node('orphan', 'missing'), 'orphan', 'missing');

        $lost = \iterator_to_array($tree->iterateLostChildren(), false);

        Assert::same(self::ids($lost), ['orphan']);
        Assert::same($lost[0]->parentId, 'missing');
        Assert::same($tree->all['root']->children, []);
        Assert::same(\count($tree), 2);
    }

    public function testIteratorsYieldAllBranchesAndItemsInInsertionOrder(): void
    {
        $tree = new Tree();
        $a = self::node('a');
        $b = self::node('b', 'a');
        $lost = self::node('x', 'missing');
        $tree->addItem($a, 'a', null);
        $tree->addItem($b, 'b', 'a');
        $tree->addItem($lost, 'x', 'missing');

        $branches = \iterator_to_array($tree);

        Assert::same(\array_keys($branches), ['a', 'b', 'x']);
        Assert::same($branches['b']->item, $b);
        Assert::same(\iterator_to_array($tree->iterateAll(), false), [$a, $b, $lost]);
    }

    public function testItemsSortedV0YieldsDepthFirstInInsertionOrder(): void
    {
        $tree = self::sampleTree();

        Assert::same(self::itemIds($tree->getItemsSortedV0(null)), ['r1', 'a', 'a1', 'a2', 'b', 'b1', 'r2', 'c']);
    }

    public function testItemsSortedV0SortsSiblingsWithSorter(): void
    {
        $tree = self::sampleTree();

        $items = $tree->getItemsSortedV0(self::descending(...));

        Assert::same(self::itemIds($items), ['r1', 'b', 'b1', 'a', 'a2', 'a1', 'r2', 'c']);
    }

    public function testItemsSortedV1YieldsLevelByLevel(): void
    {
        $tree = self::sampleTree();

        $items = self::itemIds($tree->getItemsSortedV1(null));

        Assert::array(\array_slice($items, 0, 2))->sameElementsAs(['r1', 'r2']);
        Assert::array(\array_slice($items, 2, 3))->sameElementsAs(['a', 'b', 'c']);
        Assert::array(\array_slice($items, 5))->sameElementsAs(['a1', 'a2', 'b1']);
        Assert::count($items, 8);
    }

    public function testItemsSortedV1SortsEachLevelWithSorter(): void
    {
        $tree = self::sampleTree();

        $items = $tree->getItemsSortedV1(self::descending(...));

        Assert::same(self::itemIds($items), ['r1', 'r2', 'c', 'b', 'a', 'b1', 'a2', 'a1']);
    }

    public function testDestructReleasesBranches(): void
    {
        $tree = self::sampleTree();
        $branch = $tree->all['a'];

        unset($tree);

        Assert::false(isset($branch->item));
        Assert::false(isset($branch->children));
    }

    #[DataProvider('topNumbers')]
    public function testTop(int $top): void
    {
        $edges = self::generateEdges();
        $tree = Tree::fromEdgesList(
            $edges,
            static fn(Edge $edge) => $edge->callee,
            static fn(Edge $edge) => $edge->caller,
        );

        $result = $tree->top($top, static fn(Branch $a, Branch $b) => $b->item->cost->wt <=> $a->item->cost->wt);

        Assert::count($result, \min($top, \count($edges)));

        $topUnoptimized = \array_map(static fn(Edge $e) => $e->cost->wt, $edges);
        \rsort($topUnoptimized);
        $topUnoptimized = \array_slice($topUnoptimized, 0, $top);
        $topVals = \array_map(static fn(Branch $b) => $b->item->cost->wt, $result);
        Assert::same($topVals, $topUnoptimized);
    }

    /**
     * r1 -> (a -> (a1, a2), b -> (b1)), r2 -> (c)
     *
     * @return Tree<\stdClass>
     */
    private static function sampleTree(): Tree
    {
        $tree = new Tree();
        foreach ([
            ['r1', null], ['a', 'r1'], ['b', 'r1'], ['a1', 'a'], ['a2', 'a'], ['b1', 'b'], ['r2', null], ['c', 'r2'],
        ] as [$id, $parent]) {
            $tree->addItem(self::node($id, $parent), $id, $parent);
        }

        return $tree;
    }

    private static function descending(Branch $left, Branch $right): int
    {
        return $right->id <=> $left->id;
    }

    private static function node(string $id, ?string $parent = null): \stdClass
    {
        $node = new \stdClass();
        $node->id = $id;
        $node->parent = $parent;

        return $node;
    }

    /**
     * @param iterable<Branch<\stdClass>> $branches
     *
     * @return list<string>
     */
    private static function ids(iterable $branches): array
    {
        $ids = [];
        foreach ($branches as $branch) {
            $ids[] = $branch->id;
        }

        return $ids;
    }

    /**
     * @param iterable<\stdClass> $items
     *
     * @return list<string>
     */
    private static function itemIds(iterable $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            $ids[] = $item->id;
        }

        return $ids;
    }

    /**
     * @return array<Edge>
     */
    private static function generateEdges(int $multiplier = 20): array
    {
        $result = [];

        for ($i = 0; $i < $multiplier; $i++) {
            for ($j = 0; $j <= $i; $j++) {
                $result[] = new Edge(
                    $i === 0 ? null : 'item-' . ($i - 1) . "-$j",
                    "item-$i-$j",
                    new Cost(
                        ct: (int) $i ** $i,
                        wt: ($multiplier - $i) * 1000 + $j,
                        cpu: ($multiplier - $i) * 10,
                        mu: (int) $i * 1000,
                        pmu: (int) $i * 1000,
                    ),
                );
            }
        }

        return $result;
    }
}
