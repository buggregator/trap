<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Compaction;

use Buggregator\Trap\Support\TimeSlice;

/**
 * Call tree a builder reads, merged by call paths or made of calls. Node 0 holds the roots.
 *
 * The raw tree may take {@see self::RAW_FACTOR} times the node limit of the profile, its nodes being much smaller
 * than the profile's. Past that, it is compacted to the node limit, and the build goes on; what comes later
 * on a pruned path goes to the `(other)` node there. When the compaction can't make room, what would add nodes
 * is summed in a `(truncated)` node of the parent.
 *
 * @internal
 */
final class Nodes
{
    /** Frame of node 0. */
    public const TOP = -1;

    /** Nodes of the raw tree, by the node limit of the profile. */
    public const RAW_FACTOR = 2;

    public readonly FlatTree $tree;
    public readonly Report $report;

    /** @var int<0, max> Samples with no frames. */
    public int $emptyStacks = 0;

    /** @var list<int> Open calls of an evented profile: kept by the compaction, and their indexes updated. */
    public array $open = [];

    /** @var array<int, int> Nodes by `parent * frameCount + frame`, to merge the stacks of samples */
    private array $byPath = [];

    /** @var array<int, true> Pruned paths, by `parent * frameCount + frame` */
    private array $routes = [];

    /** @var array<int, int> `(truncated)` nodes by parents */
    private array $truncatedOf = [];

    /** @var array<int, int> `(other)` nodes by parents */
    private array $otherOf = [];

    /** @var int<1, max> */
    private readonly int $rawLimit;

    private bool $truncating = false;

    /**
     * @param positive-int $maxNodes Node limit of the profile.
     * @param int<0, max> $frameCount
     * @param bool $calls The nodes are calls, not merged stacks: a count is the calls of the node only.
     */
    public function __construct(
        public readonly int $maxNodes,
        private readonly int $frameCount,
        bool $calls = false,
        public readonly ?TimeSlice $timeSlice = null,
    ) {
        $this->tree = new FlatTree(inclusiveCounts: !$calls);
        $this->tree->add(-1, self::TOP);
        $this->report = new Report();
        // A sample may be a thousand frames deep: a small limit would compact on every sample
        $this->rawLimit = \max($maxNodes * self::RAW_FACTOR, $maxNodes + 10_000);
    }

    /**
     * @return int The child of the frame, added when the parent has none; a folded child past the limits.
     *         -1 when the compaction folded the parent: the parent's node holds the sample already.
     */
    public function child(int $parent, int $frame): int
    {
        $key = $parent * $this->frameCount + $frame;
        $node = $this->byPath[$key] ?? null;
        if ($node !== null) {
            return $node;
        }

        if ($this->tree->size() >= $this->rawLimit) {
            $parent = $this->makeRoom($parent);
            if ($parent === -1 || $this->truncating) {
                return $parent === -1 ? -1 : $this->truncatedChild($parent);
            }
            $key = $parent * $this->frameCount + $frame;
            $node = $this->byPath[$key] ?? null;
            if ($node !== null) {
                return $node;
            }
        }

        if (isset($this->routes[$key])) {
            return $this->otherOf[$parent] ??= $this->tree->add($parent, FlatTree::OTHER);
        }

        return $this->byPath[$key] = $this->tree->add($parent, $frame);
    }

    /**
     * @return int A new child, not merged with the children of the same frame: a call of an evented profile.
     *         A folded child past the limits; -1 when the compaction folded the parent.
     */
    public function call(int $parent, int $frame): int
    {
        if ($this->tree->size() >= $this->rawLimit) {
            $parent = $this->makeRoom($parent);
            if ($parent === -1 || $this->truncating) {
                return $parent === -1 ? -1 : $this->truncatedChild($parent);
            }
        }

        return $this->tree->add($parent, $frame);
    }

    /**
     * The node sums what doesn't fit, so its children are not kept.
     */
    public function isFolded(int $node): bool
    {
        return $this->tree->frame[$node] < self::TOP;
    }

    /**
     * Compacts the tree to the node limit of the profile, once it is read.
     */
    public function compact(): void
    {
        $this->tree->size() > $this->maxNodes + 1 and $this->update($this->compactTo($this->maxNodes + 1));
    }

    /**
     * @return list<int>
     */
    public function roots(): array
    {
        $roots = [];
        foreach ($this->tree->parent as $node => $parent) {
            $parent === 0 and $roots[] = $node;
        }

        return $roots;
    }

    /**
     * @return int The parent's new index, or -1 when it was folded.
     */
    private function makeRoom(int $parent): int
    {
        if ($this->truncating) {
            return $parent;
        }

        $map = $this->compactTo($this->maxNodes + 1, $parent);
        $this->update($map);
        // Pinned, the parent is kept: it may only have been merged into a sibling
        $parent = $map[$parent];
        $this->truncating = $this->tree->size() > (float) $this->rawLimit * 0.9;

        return $this->isFolded($parent) ? -1 : $parent;
    }

    /**
     * @param int<1, max> $target
     *
     * @return array<int, int>
     */
    private function compactTo(int $target, int $pin = 0): array
    {
        $pinned = [$pin => true];
        foreach ($this->open as $node) {
            $node >= 0 and $pinned[$node] = true;
        }

        $pruned = [];
        $map = Compactor::compact($this->tree, $target, $this->report, $pinned, $pruned);
        foreach ($pruned as [$parent, $frame]) {
            $this->routes[$parent * $this->frameCount + $frame] = true;
        }

        return $map;
    }

    /**
     * Takes the new indexes of the nodes after a compaction.
     *
     * @param array<int, int> $map
     */
    private function update(array $map): void
    {
        $routes = [];
        foreach ($this->routes as $key => $_) {
            $parent = $map[\intdiv($key, $this->frameCount)] ?? -1;
            $parent === -1 || $this->isFolded($parent)
                or $routes[$parent * $this->frameCount + $key % $this->frameCount] = true;
        }
        $this->routes = $routes;

        $this->byPath = $this->truncatedOf = $this->otherOf = [];
        foreach ($this->tree->frame as $node => $frame) {
            $parent = $this->tree->parent[$node];
            match (true) {
                $frame === FlatTree::TRUNCATED => $this->truncatedOf[$parent] = $node,
                $frame === FlatTree::OTHER => $this->otherOf[$parent] = $node,
                $frame >= 0 => $this->byPath[$parent * $this->frameCount + $frame] = $node,
                default => null,
            };
        }

        foreach ($this->open as $i => $node) {
            $this->open[$i] = $node === -1 ? -1 : $map[$node];
        }
    }

    private function truncatedChild(int $parent): int
    {
        ++$this->report->truncated;
        return $this->truncatedOf[$parent] ??= $this->tree->add($parent, FlatTree::TRUNCATED);
    }
}
