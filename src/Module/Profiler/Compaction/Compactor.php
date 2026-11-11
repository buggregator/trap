<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Compaction;

/**
 * Shrinks a call tree that is over its node limit, in stages, each lossy but keeping the sums:
 *
 * 1. sibling calls of the same function are merged, summing their costs and counts: a tree of calls,
 *    such as Xdebug's, becomes a tree of call paths;
 * 2. direct recursion deeper than {@see self::RECURSION_DEPTH} calls in a row is merged into the deepest kept call;
 * 3. the cold subtrees are pruned: the nodes of the smallest inclusive value are summed in an `(other)` node
 *    of their parent, until the tree is down to the target.
 *
 * The inclusive costs of the kept nodes don't change, and a folded node takes the costs of what it replaces.
 * The nodes with no parent are kept as they are, and so are the pinned ones with their ancestors:
 * {@see Nodes} pins the open calls and the roots of the profile, the children of its node 0.
 *
 * @internal
 */
final class Compactor
{
    /** Calls of a function in a row kept by the merging of recursion. */
    public const RECURSION_DEPTH = 8;

    /**
     * @param int<1, max> $target Nodes to get down to.
     * @param array<int, true> $pinned Nodes kept with their ancestors, not merged into their callers: open calls.
     * @param list<array{int, int}> $pruned Gets the parents and frames of the pruned children, by new indexes,
     *        to send there what comes later on the same path.
     *
     * @return array<int, int> New indexes by old ones. A pruned node goes to the `(other)` node that sums it.
     */
    public static function compact(
        FlatTree $tree,
        int $target,
        Report $report,
        array $pinned = [],
        array &$pruned = [],
    ): array {
        $size = $tree->size();
        $longestRun = 0;
        $map = self::rebuild($tree, $pinned, longestRun: $longestRun);
        $report->merged += $size - $tree->size();
        $pinned = self::remapSet($pinned, $map);

        // With no deeper recursion the merged tree would be copied as it is
        if ($tree->size() > $target && $longestRun > self::RECURSION_DEPTH) {
            $size = $tree->size();
            $step = self::rebuild($tree, $pinned, collapse: true);
            $report->collapsed += $size - $tree->size();
            $map = self::compose($map, $step);
            $pinned = self::remapSet($pinned, $step);
        }

        if ($tree->size() > $target) {
            $folded = 0;
            $step = self::rebuild($tree, $pinned, kept: self::hottest($tree, $target, $pinned), pruned: $pruned, folded: $folded);
            $report->pruned += $folded;
            $map = self::compose($map, $step);
        }

        return $map;
    }

    /**
     * Copies the tree with the siblings of the same frame merged, from the roots down.
     *
     * @param array<int, true> $pinned
     * @param bool $collapse Merge the recursion deeper than {@see self::RECURSION_DEPTH} into the deepest kept call.
     * @param array<int, bool>|null $kept Nodes to keep by index: the others are summed in an `(other)` node of their parent.
     * @param list<array{int, int}> $pruned Gets the new parents and the frames of the pruned children.
     * @param int $folded Gets the number of the nodes summed in `(other)` nodes.
     * @param int $longestRun Gets the most calls of the same function in a row in the new tree.
     *
     * @return array<int, int> New indexes by old ones.
     */
    private static function rebuild(
        FlatTree $tree,
        array $pinned,
        bool $collapse = false,
        ?array $kept = null,
        array &$pruned = [],
        int &$folded = 0,
        int &$longestRun = 0,
    ): array {
        $frames = $tree->frame;
        $parents = $tree->parent;
        $values = $tree->value;
        $memories = $tree->memory;
        $counts = $tree->count;
        $inclusiveCounts = $tree->inclusiveCounts;
        /** @var array<int, int> $newFrame */
        $newFrame = [];
        /** @var array<int, int> $newParent */
        $newParent = [];
        /** @var array<int, float> $newValue */
        $newValue = [];
        /** @var array<int, float> $newMemory */
        $newMemory = [];
        /** @var array<int, int> $newCount */
        $newCount = [];
        $size = \count($frames);
        $map = $size === 0 ? [] : \array_fill(0, $size, -1);
        /** @var array<int, int> $run Calls of the same function in a row down to a new node */
        $run = [];
        /** @var array<int, int> $byKey New nodes by new parents and frames */
        $byKey = [];
        $multiplier = ($frames === [] ? 0 : \max($frames)) + 4;

        foreach (self::topDown($tree) as $node) {
            $frame = $frames[$node];
            $parent = $parents[$node];
            if ($parent === -1) {
                $map[$node] = $target = \count($newFrame);
                $newFrame[] = $frame;
                $newParent[] = -1;
                $newValue[] = $values[$node];
                $newMemory[] = $memories[$node];
                $newCount[] = $counts[$node];
                $run[$target] = 1;
                continue;
            }

            $to = $map[$parent];
            // Under a pruned node: the costs are in it already, except the calls of the nodes below
            if ($kept !== null && !$kept[$parent]) {
                $map[$node] = $to;
                $inclusiveCounts or $newCount[$to] += $counts[$node];
                ++$folded;
                continue;
            }

            if ($kept !== null && !$kept[$node]) {
                ++$folded;
                $frame >= 0 and $pruned[] = [$to, $frame];
                $frame = FlatTree::OTHER;
            } elseif ($collapse && $frame >= 0 && $frame === $newFrame[$to] && $run[$to] >= self::RECURSION_DEPTH
                && !isset($pinned[$node])
            ) {
                // The caller's inclusive costs hold the call already
                $map[$node] = $to;
                $inclusiveCounts or $newCount[$to] += $counts[$node];
                continue;
            }

            $key = $to * $multiplier + $frame + 3;
            $target = $byKey[$key] ?? null;
            if ($target === null) {
                $target = $byKey[$key] = \count($newFrame);
                $newFrame[] = $frame;
                $newParent[] = $to;
                $newValue[] = 0.0;
                $newMemory[] = 0.0;
                $newCount[] = 0;
                $run[$target] = $length = $frame === $newFrame[$to] ? $run[$to] + 1 : 1;
                $length > $longestRun and $longestRun = $length;
            }
            $newValue[$target] += $values[$node];
            $newMemory[$target] += $memories[$node];
            $newCount[$target] += $counts[$node];
            $map[$node] = $target;
        }
        unset($frames, $parents, $values, $memories, $counts, $byKey, $run);

        $tree->frame = $newFrame;
        $tree->parent = $newParent;
        $tree->value = $newValue;
        $tree->memory = $newMemory;
        $tree->count = $newCount;

        return $map;
    }

    /**
     * The nodes of the largest values that, with the `(other)` nodes the rest needs, fit the target.
     *
     * The nodes are ranked by value, and of equal values the first ones go first. The kept ones are the first
     * of the ranking, the roots and the pinned nodes with their ancestors; the number of the ranked ones
     * is lowered until the kept nodes and the `(other)` nodes of their parents fit.
     *
     * @param int<1, max> $target
     * @param array<int, true> $pinned
     *
     * @return array<int, bool> Whether to keep the node, by index.
     */
    private static function hottest(FlatTree $tree, int $target, array $pinned): array
    {
        $parents = $tree->parent;
        $size = \count($parents);
        // A profile of no time, such as one of sample counts, is ranked by the counts
        $values = \array_sum($tree->value) > 0.0 ? $tree->value : \array_map('floatval', $tree->count);
        // The sort is stable: of equal values the first node goes first
        \arsort($values);
        $ranked = \array_keys($values);
        unset($values);
        $rank = \array_fill(0, $size, 0);
        foreach ($ranked as $position => $node) {
            $rank[$node] = $position;
        }

        $forced = \array_fill(0, $size, false);
        // Whether every node ranks after its parent, as with inclusive values
        $closed = true;
        foreach ($parents as $node => $parent) {
            if ($parent === -1) {
                $forced[$node] = true;
            } elseif ($rank[$parent] > $rank[$node]) {
                $closed = false;
            }
        }
        foreach ($pinned as $node => $_) {
            $forced[$node] = true;
        }
        unset($rank);

        $limit = \min($target, $size);
        return $closed
            ? self::hottestClosed($parents, $ranked, $forced, $pinned, $target, $limit)
            : self::hottestAny($parents, $ranked, $forced, $pinned, $target, $limit);
    }

    /**
     * The first ranked nodes have all their ancestors among them, so the kept set only loses nodes
     * as the limit is lowered: the count of the kept and `(other)` nodes is updated node by node.
     *
     * @param array<int, int> $parents
     * @param list<int> $ranked
     * @param array<int, bool> $forced Roots and pinned nodes.
     * @param array<int, true> $pinned
     * @param int<1, max> $target
     * @param int<0, max> $limit
     *
     * @return array<int, bool>
     */
    private static function hottestClosed(
        array $parents,
        array $ranked,
        array $forced,
        array $pinned,
        int $target,
        int $limit,
    ): array {
        // The ancestors of the pinned nodes are kept whatever the limit: a kept ranked node has its ancestors kept
        foreach ($pinned as $node => $_) {
            for ($up = $parents[$node]; $up !== -1 && !$forced[$up]; $up = $parents[$up]) {
                $forced[$up] = true;
            }
        }

        $kept = $forced;
        for ($position = 0; $position < $limit; ++$position) {
            $kept[$ranked[$position]] = true;
        }

        /** @var array<int, int> $dropped Dropped children by parents */
        $dropped = \array_fill(0, \count($parents), 0);
        $count = 0;
        foreach ($parents as $node => $parent) {
            $kept[$node] ? ++$count : ++$dropped[$parent];
        }
        foreach ($dropped as $node => $children) {
            // A parent with a dropped child gets an `(other)` node
            $children > 0 && $kept[$node] and ++$count;
        }

        while ($count > $target && $limit > 1) {
            $next = \max(1, \min($limit - 1, (int) \ceil($limit * $target / $count)));
            for ($position = $limit - 1; $position >= $next; --$position) {
                $node = $ranked[$position];
                if ($forced[$node]) {
                    continue;
                }
                $kept[$node] = false;
                --$count;
                $dropped[$node] > 0 and --$count;
                $parent = $parents[$node];
                ++$dropped[$parent] === 1 && $kept[$parent] and ++$count;
            }
            $limit = $next;
        }

        return $kept;
    }

    /**
     * A ranked node may go before its parent: the kept set is made anew for every limit.
     *
     * @param array<int, int> $parents
     * @param list<int> $ranked
     * @param array<int, bool> $forced Roots and pinned nodes.
     * @param array<int, true> $pinned
     * @param int<1, max> $target
     * @param int<0, max> $limit
     *
     * @return array<int, bool>
     */
    private static function hottestAny(
        array $parents,
        array $ranked,
        array $forced,
        array $pinned,
        int $target,
        int $limit,
    ): array {
        $size = \count($parents);
        do {
            $kept = $forced;
            for ($position = 0; $position < $limit; ++$position) {
                $kept[$ranked[$position]] = true;
            }
            foreach ($pinned as $node => $_) {
                for ($up = $parents[$node]; $up !== -1 && !$kept[$up]; $up = $parents[$up]) {
                    $kept[$up] = true;
                }
            }

            // A parent with a dropped child gets an `(other)` node
            $hasOther = \array_fill(0, $size, false);
            $count = 0;
            foreach ($parents as $node => $parent) {
                if ($kept[$node]) {
                    ++$count;
                } elseif ($kept[$parent] && !$hasOther[$parent]) {
                    $hasOther[$parent] = true;
                    ++$count;
                }
            }
            unset($hasOther);

            $fits = $count <= $target || $limit <= 1;
            $limit = \max(1, \min($limit - 1, (int) \ceil($limit * $target / $count)));
        } while (!$fits);

        return $kept;
    }

    /**
     * @return array<int, int> Node indexes by position, parents before their children, siblings in their order.
     */
    private static function topDown(FlatTree $tree): array
    {
        $size = $tree->size();
        if ($size === 0) {
            return [];
        }

        // A node comes after its parent: the depths are known in one pass
        $parents = $tree->parent;
        /** @var array<int, int> $depth */
        $depth = [];
        /** @var array<int, int> $levels */
        $levels = [];
        $ordered = true;
        $previous = 0;
        for ($node = 0; $node < $size; ++$node) {
            $parent = $parents[$node];
            $level = $parent === -1 ? 0 : $depth[$parent] + 1;
            $depth[] = $level;
            $levels[$level] = ($levels[$level] ?? 0) + 1;
            $level < $previous and $ordered = false;
            $previous = $level;
        }
        if ($ordered) {
            return \range(0, $size - 1);
        }

        // Counting sort by depth; ascending indexes in each level keep the siblings in their order
        \ksort($levels);
        /** @var array<int, int> $start */
        $start = [];
        $position = 0;
        foreach ($levels as $level => $count) {
            $start[$level] = $position;
            $position += $count;
        }
        $order = \array_fill(0, $size, 0);
        foreach ($depth as $node => $level) {
            $order[$start[$level]++] = $node;
        }

        return $order;
    }

    /**
     * @param array<int, int> $first
     * @param array<int, int> $second
     *
     * @return array<int, int>
     */
    private static function compose(array $first, array $second): array
    {
        foreach ($first as $old => $new) {
            $first[$old] = $second[$new];
        }

        return $first;
    }

    /**
     * @param array<int, true> $set
     * @param array<int, int> $map
     *
     * @return array<int, true>
     */
    private static function remapSet(array $set, array $map): array
    {
        $result = [];
        foreach ($set as $node => $_) {
            $result[$map[$node]] = true;
        }

        return $result;
    }
}
