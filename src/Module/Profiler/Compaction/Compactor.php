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
        $map = self::rebuild($tree, $pinned);
        $report->merged += $size - $tree->size();
        $pinned = self::remapSet($pinned, $map);

        if ($tree->size() > $target) {
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
    ): array {
        $result = new FlatTree($tree->inclusiveCounts);
        $size = $tree->size();
        $map = $size === 0 ? [] : \array_fill(0, $size, -1);
        /** @var array<int, int> $run Calls of the same function in a row down to a new node */
        $run = [];
        /** @var array<int, int> $byKey New nodes by new parents and frames */
        $byKey = [];
        $multiplier = ($tree->frame === [] ? 0 : \max($tree->frame)) + 4;

        foreach (self::topDown($tree) as $node) {
            $frame = $tree->frame[$node];
            $parent = $tree->parent[$node];
            if ($parent === -1) {
                $map[$node] = $result->add(-1, $frame, $tree->value[$node], $tree->count[$node], $tree->memory[$node]);
                $run[$map[$node]] = 1;
                continue;
            }

            $newParent = $map[$parent];
            // Under a pruned node: the costs are in it already, except the calls of the nodes below
            if ($kept !== null && !$kept[$parent]) {
                $map[$node] = $newParent;
                $tree->inclusiveCounts or $result->count[$newParent] += $tree->count[$node];
                ++$folded;
                continue;
            }

            if ($kept !== null && !$kept[$node]) {
                ++$folded;
                $frame >= 0 and $pruned[] = [$newParent, $frame];
                $frame = FlatTree::OTHER;
            } elseif ($collapse && !isset($pinned[$node]) && $frame >= 0 && $frame === $result->frame[$newParent]
                && $run[$newParent] >= self::RECURSION_DEPTH
            ) {
                // The caller's inclusive costs hold the call already
                $map[$node] = $newParent;
                $tree->inclusiveCounts or $result->count[$newParent] += $tree->count[$node];
                continue;
            }

            $key = $newParent * $multiplier + $frame + 3;
            $target = $byKey[$key] ?? null;
            if ($target === null) {
                $target = $byKey[$key] = $result->add($newParent, $frame);
                $run[$target] = $frame === $result->frame[$newParent] ? $run[$newParent] + 1 : 1;
            }
            $result->value[$target] += $tree->value[$node];
            $result->memory[$target] += $tree->memory[$node];
            $result->count[$target] += $tree->count[$node];
            $map[$node] = $target;
        }

        $tree->frame = $result->frame;
        $tree->parent = $result->parent;
        $tree->value = $result->value;
        $tree->memory = $result->memory;
        $tree->count = $result->count;

        return $map;
    }

    /**
     * The nodes of the largest values that, with the `(other)` nodes the rest needs, fit the target.
     * A node's value is inclusive and the tree is in the order of {@see self::rebuild()}, parents first:
     * of equal values the first ones are kept, so the ancestors of a kept node are kept too.
     *
     * @param int<1, max> $target
     * @param array<int, true> $pinned
     *
     * @return array<int, bool> Whether to keep the node, by index.
     */
    private static function hottest(FlatTree $tree, int $target, array $pinned): array
    {
        // A profile of no time, such as one of sample counts, is ranked by the counts
        $values = \array_sum($tree->value) > 0.0 ? $tree->value : \array_map('floatval', $tree->count);
        $sorted = $values;
        \rsort($sorted);
        $size = \count($values);

        $limit = \min($target, $size);
        do {
            $threshold = $sorted[\max(0, $limit - 1)];
            $kept = \array_fill(0, $size, false);
            $count = 0;
            $ties = $limit;
            foreach ($values as $node => $value) {
                if ($value > $threshold) {
                    $kept[$node] = true;
                    --$ties;
                }
            }
            foreach ($values as $node => $value) {
                if ($ties > 0 && $value === $threshold && !$kept[$node]) {
                    $kept[$node] = true;
                    --$ties;
                }
            }
            foreach ($tree->parent as $node => $parent) {
                ($parent === -1 || isset($pinned[$node])) and $kept[$node] = true;
            }
            foreach ($pinned as $node => $_) {
                for ($up = $tree->parent[$node]; $up !== -1 && !$kept[$up]; $up = $tree->parent[$up]) {
                    $kept[$up] = true;
                }
            }

            // A parent with a dropped child gets an `(other)` node
            $hasOther = \array_fill(0, $size, false);
            foreach ($tree->parent as $node => $parent) {
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

        $depth = \array_fill(0, $size, -1);
        $levels = [];
        for ($node = 0; $node < $size; ++$node) {
            if ($depth[$node] === -1) {
                // Up to an ancestor of known depth, then down
                $path = [];
                for ($up = $node; $up !== -1 && $depth[$up] === -1; $up = $tree->parent[$up]) {
                    $path[] = $up;
                }
                $level = $up === -1 ? -1 : $depth[$up];
                for ($i = \count($path) - 1; $i >= 0; --$i) {
                    $depth[$path[$i]] = ++$level;
                    $levels[$level] = ($levels[$level] ?? 0) + 1;
                }
            }
        }

        // Counting sort by depth; ascending indexes in each level keep the siblings in their order
        \ksort($levels);
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
