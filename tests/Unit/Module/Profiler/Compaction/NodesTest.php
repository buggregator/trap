<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Compaction;

use Buggregator\Trap\Module\Profiler\Compaction\FlatTree;
use Buggregator\Trap\Module\Profiler\Compaction\Nodes;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(Nodes::class)]
#[Test]
final class NodesTest
{
    /** Raw limit of a profile of {@see self::MAX_NODES} nodes. */
    private const RAW_LIMIT = 10_010;

    private const MAX_NODES = 10;

    public function testFindsChildMergedByCompactionInsteadOfAddingIt(): void
    {
        $nodes = new Nodes(self::MAX_NODES, frameCount: self::RAW_LIMIT + 10, calls: true);
        $tree = $nodes->tree;
        $main = $nodes->call(0, 1);
        // Two calls of the same function: merged into one node by the compaction
        $first = $nodes->call($main, 2);
        $nodes->call($main, 2);
        $tree->value[$main] = $tree->value[$first] = 100.0;
        $cold = $nodes->call(0, 3);
        for ($frame = 4; $tree->size() < self::RAW_LIMIT; ++$frame) {
            $nodes->call($cold, $frame);
        }

        $child = $nodes->child($main, 2);

        $size = $tree->size();
        Assert::true($size <= self::MAX_NODES + 1);
        Assert::same($tree->frame[$child], 2);
        Assert::same($nodes->child($tree->parent[$child], 2), $child);
        Assert::same($tree->size(), $size);
    }

    public function testTruncatesWhatPinnedRootsLeaveNoRoomFor(): void
    {
        $hardLimit = self::RAW_LIMIT * Nodes::HARD_FACTOR;
        $nodes = new Nodes(self::MAX_NODES, frameCount: $hardLimit + 10);
        $tree = $nodes->tree;
        // The roots are pinned: the compaction can't take them
        for ($frame = 0; $tree->size() < $hardLimit; ++$frame) {
            $nodes->sample([$frame], 1.0, 1);
        }

        $nodes->sample([$frame, 1], 5.0, 1);

        $truncated = \array_search(FlatTree::TRUNCATED, $tree->frame, true);
        Assert::true(\is_int($truncated));
        Assert::true($nodes->isFolded($truncated));
        Assert::same($tree->parent[$truncated], 0);
        // The rest of the stack is summed in the folded node, not added under it
        Assert::same($tree->value[$truncated], 5.0);
        Assert::same($tree->count[$truncated], 1);
        Assert::same($tree->size(), $hardLimit + 1);
        Assert::same($nodes->report->truncated, 1);
    }

    public function testTriesCompactionAgainAfterRawLimitOfFoldedCalls(): void
    {
        $hardLimit = self::RAW_LIMIT * Nodes::HARD_FACTOR;
        $nodes = new Nodes(self::MAX_NODES, frameCount: $hardLimit + 10, calls: true);
        $tree = $nodes->tree;
        for ($frame = 0; $tree->size() < $hardLimit; ++$frame) {
            $nodes->call(0, $frame);
        }

        // Folded until the raw limit, the same `(truncated)` node taking them all
        $folded = [];
        for ($i = 0; $i < self::RAW_LIMIT; ++$i) {
            $folded[$nodes->call(0, $frame + $i)] = true;
        }
        Assert::same(\count($folded), 1);
        Assert::same($tree->frame[\array_key_first($folded)], FlatTree::TRUNCATED);
        Assert::same($nodes->report->truncated, self::RAW_LIMIT);

        // Tried again: the roots are still pinned, and the `(truncated)` node found after the compaction is reused
        $again = $nodes->call(0, 1);
        Assert::true($nodes->isFolded($again));
        Assert::same($tree->frame[$again], FlatTree::TRUNCATED);
        Assert::same(\array_count_values($tree->frame)[FlatTree::TRUNCATED], 1);
        Assert::same($nodes->report->truncated, self::RAW_LIMIT + 1);
    }
}
