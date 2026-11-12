<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Compaction;

use Buggregator\Trap\Module\Profiler\Compaction\Compactor;
use Buggregator\Trap\Module\Profiler\Compaction\FlatTree;
use Buggregator\Trap\Module\Profiler\Compaction\Report;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Covers(Compactor::class)]
#[Covers(FlatTree::class)]
#[Covers(Report::class)]
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

    public function testNeverDropsPinnedNodeRankedAmongTheTrimmed(): void
    {
        $tree = new FlatTree(inclusiveCounts: false);
        $main = $tree->add(-1, 0, 100.0, 1);
        $open = $tree->add($main, 1, 90.0, 1);
        $tree->add($main, 2, 50.0, 1);
        $tree->add($main, 3, 40.0, 1);

        $map = Compactor::compact($tree, 2, new Report(), pinned: [$open => true]);

        // Over the target: a pinned node is kept whatever it costs
        Assert::same(self::shape($tree), ['0:100:1' => ['1:90:1' => [], '-3:90:2' => []]]);
        Assert::same($tree->frame[$map[$open]], 1);
    }

    public function testPrunesByCountsWhenChildOutranksItsParent(): void
    {
        // A profile of no time: the self counts rank `hot` before its caller `a`
        $tree = new FlatTree(inclusiveCounts: false);
        $main = $tree->add(-1, 0, 0.0, 1);
        $a = $tree->add($main, 1, 0.0, 1);
        $hot = $tree->add($a, 2, 0.0, 10);
        $tree->add($main, 3, 0.0, 3);
        $tree->add($main, 4, 0.0, 2);

        $map = Compactor::compact($tree, 4, new Report());

        // `hot` goes with its dropped caller into the `(other)` node
        Assert::same(self::shape($tree), ['0:0:1' => ['-3:0:13' => [], '3:0:3' => []]]);
        Assert::same($map[$hot], $map[$a]);
    }

    public function testKeepsAncestorsOfPinnedNodeOutrankingThem(): void
    {
        $tree = new FlatTree(inclusiveCounts: false);
        $main = $tree->add(-1, 0, 0.0, 1);
        $a = $tree->add($main, 1, 0.0, 1);
        $open = $tree->add($a, 2, 0.0, 10);
        $tree->add($main, 3, 0.0, 3);
        $tree->add($main, 4, 0.0, 2);

        $map = Compactor::compact($tree, 4, new Report(), pinned: [$open => true]);

        Assert::same(self::shape($tree), ['0:0:1' => ['1:0:1' => ['2:0:10' => []], '-3:0:5' => []]]);
        Assert::same($tree->parent[$map[$open]], $map[$a]);
    }

    public function testCompactsEmptyTree(): void
    {
        $tree = new FlatTree(inclusiveCounts: false);

        $map = Compactor::compact($tree, 1, $report = new Report());

        Assert::same($map, []);
        Assert::same($tree->size(), 0);
        Assert::true($report->isEmpty());
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

    public function testMeasuresShareOfRootValueInFoldedNodes(): void
    {
        // Node 0 holds the roots; the folded nodes sit at any depth
        $tree = new FlatTree(inclusiveCounts: false);
        $holder = $tree->add(-1, 0, 0.0, 0);
        $a = $tree->add($holder, 1, 60.0, 1);
        $tree->add($holder, 2, 20.0, 1);
        $tree->add($a, FlatTree::OTHER, 5.0, 1);
        $tree->add($a, FlatTree::TRUNCATED, 2.5, 1);

        ($report = new Report())->measure($tree);

        Assert::same($report->folded, 9.4);
        Assert::same($report->toArray()['folded'], 9.4);
    }

    public function testMeasuresMemoryInFoldedNodesOfTreeOfNoTime(): void
    {
        // A heap profile: the counts are allocated objects, ranked after the memory
        $tree = new FlatTree(inclusiveCounts: true);
        $holder = $tree->add(-1, 0, 0.0, 12, 100.0);
        $main = $tree->add($holder, 1, 0.0, 12, 100.0);
        $tree->add($main, 2, 0.0, 3, 75.0);
        $tree->add($main, FlatTree::OTHER, 0.0, 9, 25.0);

        ($report = new Report())->measure($tree);

        Assert::same($report->folded, 25.0);
    }

    public function testMeasuresCallsInFoldedNodesOverAllNodes(): void
    {
        // Calls of a node alone: the roots don't sum their callees
        $tree = new FlatTree(inclusiveCounts: false);
        $holder = $tree->add(-1, 0, 0.0, 0);
        $main = $tree->add($holder, 1, 0.0, 1);
        $tree->add($main, 2, 0.0, 2);
        $tree->add($main, FlatTree::OTHER, 0.0, 1);

        ($report = new Report())->measure($tree);

        Assert::same($report->folded, 25.0);
    }

    public function testMeasuresNothingFoldedInTreeOfNoCost(): void
    {
        $tree = new FlatTree(inclusiveCounts: true);
        $holder = $tree->add(-1, 0, 0.0, 0);
        $tree->add($holder, FlatTree::OTHER, 0.0, 0);
        $report = new Report();
        $report->folded = 50.0;

        $report->measure($tree);

        Assert::same($report->folded, 0.0);
    }

    #[DataSet([[], ''], 'nothing written')]
    #[DataSet([['merged' => 0, 'collapsed' => 0, 'pruned' => 0, 'truncated' => 0, 'folded' => 0.0], ''], 'all zero')]
    #[DataSet([['merged' => 2], '2 nodes merged into calls of the same function'], 'merged only')]
    #[DataSet([
        ['merged' => 1, 'collapsed' => 2, 'pruned' => 3, 'truncated' => 4, 'folded' => 12.5],
        '1 nodes merged into calls of the same function, 2 nodes of recursion merged into their callers, '
        . '3 nodes of cold subtrees summed in `(other)`, 4 samples or calls summed in `(truncated)`, '
        . '12.5% of the profile in the folded nodes',
    ], 'all parts')]
    #[DataSet([['pruned' => '3', 'truncated' => -1, 'folded' => 7], ''], 'values of wrong type or sign')]
    #[DataSet([['folded' => 0.5], '0.5% of the profile in the folded nodes'], 'folded only')]
    public function testSummarizesWrittenReport(array $data, string $expected): void
    {
        Assert::same(Report::summarize($data), $expected);
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
