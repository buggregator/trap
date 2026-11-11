<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Compaction;

use Buggregator\Trap\Module\Profiler\Compaction\Compactor;
use Buggregator\Trap\Module\Profiler\Compaction\FlatTree;
use Buggregator\Trap\Module\Profiler\Compaction\Report;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(Compactor::class)]
#[Test]
final class CompactorTest
{
    public function testMergesSiblingCallsOfSameFunction(): void
    {
        // main -> a, b, a -> c; the second `a` calls `c` too
        $tree = new FlatTree(inclusiveCounts: false);
        $main = $tree->add(-1, 0, 100.0, 1, 50.0);
        $tree->add($main, 1, 30.0, 1, 10.0);
        $tree->add($main, 2, 20.0, 1);
        $a = $tree->add($main, 1, 40.0, 1, 20.0);
        $tree->add($a, 3, 25.0, 1);

        $map = Compactor::compact($tree, 10, $report = new Report());

        Assert::same(self::shape($tree), ['0:100:1' => ['1:70:2' => ['3:25:1' => []], '2:20:1' => []]]);
        Assert::same($tree->memory[$map[$a]], 30.0);
        Assert::same($map[1], $map[$a]);
        Assert::same($report->toArray()['merged'], 1);
    }

    public function testKeepsRootsApart(): void
    {
        // Calls still waiting for their caller
        $tree = new FlatTree(inclusiveCounts: false);
        $tree->add(-1, 1, 10.0, 1);
        $tree->add(-1, 1, 20.0, 1);

        Compactor::compact($tree, 1, new Report());

        Assert::same(self::shape($tree), ['1:10:1' => [], '1:20:1' => []]);
    }

    public function testMergesDeepDirectRecursionIntoDeepestKeptCall(): void
    {
        $tree = new FlatTree(inclusiveCounts: false);
        $node = $tree->add(-1, 0, 100.0, 1);
        for ($level = 0; $level < 12; ++$level) {
            $node = $tree->add($node, 1, 90.0 - $level, 1);
        }
        $tree->add($node, 2, 5.0, 1);

        Compactor::compact($tree, 10, $report = new Report());

        // `main`, 8 calls of the recursion, the leaf: the 4 deeper calls are counted in the 8th
        Assert::same($tree->size(), 10);
        Assert::same($tree->value[0], 100.0);
        Assert::same(\array_count_values($tree->frame), [0 => 1, 1 => 8, 2 => 1]);
        Assert::same($tree->count[8], 5);
        Assert::same($tree->value[8], 83.0);
        Assert::same($tree->parent[9], 8);
        Assert::same($report->toArray()['collapsed'], 4);
    }

    public function testDoesNotAddSamplesOfMergedRecursion(): void
    {
        $tree = new FlatTree(inclusiveCounts: true);
        $node = $tree->add(-1, 1, 10.0, 10);
        for ($level = 0; $level < 10; ++$level) {
            $node = $tree->add($node, 1, 10.0, 10);
        }

        Compactor::compact($tree, Compactor::RECURSION_DEPTH, new Report());

        Assert::same($tree->size(), Compactor::RECURSION_DEPTH);
        Assert::same(\array_unique($tree->count), [10]);
    }

    public function testPrunesColdestSubtreesIntoOtherNodeOfParent(): void
    {
        $tree = new FlatTree(inclusiveCounts: false);
        $main = $tree->add(-1, 0, 100.0, 1);
        $hot = $tree->add($main, 1, 80.0, 1);
        $tree->add($hot, 2, 70.0, 1);
        $tree->add($hot, 3, 4.0, 1);
        $cold = $tree->add($main, 4, 6.0, 1, 64.0);
        $tree->add($cold, 5, 3.0, 2);
        $tree->add($main, 6, 5.0, 1);

        $pruned = [];
        $map = Compactor::compact($tree, 5, $report = new Report(), pruned: $pruned);

        // Each `(other)` sums the dropped children of a kept parent, with all the calls below them
        Assert::same(self::shape($tree), [
            '0:100:1' => ['1:80:1' => ['2:70:1' => [], '-3:4:1' => []], '-3:11:4' => []],
        ]);
        Assert::same($tree->memory[$map[$cold]], 64.0);
        Assert::same($map[$cold + 1], $map[$cold]);
        Assert::same(\count($pruned), 3);
        Assert::same($report->toArray()['pruned'], 4);
    }

    public function testKeepsPinnedNodesAndTheirAncestors(): void
    {
        $tree = new FlatTree(inclusiveCounts: false);
        $main = $tree->add(-1, 0, 100.0, 1);
        $tree->add($main, 1, 90.0, 1);
        $open = $tree->add($tree->add($main, 2, 1.0, 1), 3, 0.0, 1);

        $map = Compactor::compact($tree, 2, new Report(), pinned: [$open => true]);

        Assert::same($tree->frame[$map[$open]], 3);
        Assert::same($tree->frame[$tree->parent[$map[$open]]], 2);
    }

    public function testLeavesTreeWithinTargetAsItIs(): void
    {
        $tree = new FlatTree(inclusiveCounts: true);
        $main = $tree->add(-1, 0, 10.0, 2);
        $tree->add($main, 1, 4.0, 1);
        $tree->add($main, 2, 6.0, 1);

        $map = Compactor::compact($tree, 3, $report = new Report());

        Assert::same($map, [0, 1, 2]);
        Assert::true($report->isEmpty());
    }

    /**
     * @return array<string, array> Nested `frame:value:count` keys.
     */
    private static function shape(FlatTree $tree): array
    {
        $children = $tree->children();
        $map = static function (array $nodes) use (&$map, $children, $tree): array {
            $result = [];
            foreach ($nodes as $node) {
                $key = "{$tree->frame[$node]}:{$tree->value[$node]}:{$tree->count[$node]}";
                $result[$key] = $map($children[$node] ?? []);
            }
            return $result;
        };

        return $map($children[-1] ?? []);
    }
}
