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

    /** Raw limits the tree may grow to with what the compaction can't take. */
    public const HARD_FACTOR = 4;

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

    /** Size of the tree that gets it compacted */
    private int $threshold;

    /** @var int<0, max> Samples or calls folded since the truncation started or was last tried */
    private int $folds = 0;

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
        $this->rawLimit = $this->threshold = \max($maxNodes * self::RAW_FACTOR, $maxNodes + 10_000);
    }

    /**
     * @param int $parent A node that is not folded.
     *
     * @return int The child of the frame, added when the parent has none; a folded child past the limits.
     */
    public function child(int $parent, int $frame): int
    {
        $key = $parent * $this->frameCount + $frame;
        $node = $this->byPath[$key] ?? null;
        if ($node !== null) {
            return $node;
        }

        if (!$this->room($parent)) {
            return $this->truncatedChild($parent);
        }
        // The compaction may have moved the parent, or merged a sibling of the frame into it
        $key = $parent * $this->frameCount + $frame;
        $node = $this->byPath[$key] ?? null;
        if ($node !== null) {
            return $node;
        }

        if (isset($this->routes[$key])) {
            return $this->otherOf[$parent] ??= $this->tree->add($parent, FlatTree::OTHER);
        }

        return $this->byPath[$key] = $this->tree->add($parent, $frame);
    }

    /**
     * @param int $parent A node that is not folded.
     *
     * @return int A new child, not merged with the children of the same frame: a call of an evented profile.
     *         A folded child past the limits.
     */
    public function call(int $parent, int $frame): int
    {
        return $this->room($parent) ? $this->tree->add($parent, $frame) : $this->truncatedChild($parent);
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
     * Whether a node may be added, compacting the tree when it is time.
     *
     * @param int $parent Gets the parent's new index when the tree is compacted.
     */
    private function room(int &$parent): bool
    {
        if ($this->truncating) {
            // No node is added meanwhile: the compaction is tried again after as many folded samples or calls
            if (++$this->folds < $this->rawLimit) {
                return false;
            }
            $this->folds = 0;
        } elseif ($this->tree->size() < $this->threshold) {
            return true;
        }

        $parent = $this->makeRoom($parent);
        return !$this->truncating;
    }

    /**
     * Compacts the tree, and sets when to try again.
     *
     * What the compaction can't take is pinned: the open calls, the roots. The tree may then grow to twice that,
     * up to {@see self::HARD_FACTOR} times the raw limit; past that, the new nodes go to `(truncated)` nodes
     * until the raw limit is reached again, when the compaction is tried again, as calls may have ended.
     *
     * @return int The parent's new index: pinned, it is kept, though maybe merged into a sibling.
     */
    private function makeRoom(int $parent): int
    {
        $map = $this->compactTo($this->maxNodes + 1, $parent);
        $this->update($map);
        $parent = $map[$parent];

        $size = $this->tree->size();
        $hardLimit = $this->rawLimit * self::HARD_FACTOR;
        $this->truncating = $size >= $hardLimit;
        $this->threshold = match (true) {
            $this->truncating => $hardLimit,
            (float) $size > (float) $this->rawLimit * 0.9 => \min(2 * $size, $hardLimit),
            default => $this->rawLimit,
        };

        return $parent;
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
            $pinned[$node] = true;
        }
        // The roots of the profile are kept, not pruned into an `(other)` node of node 0
        foreach ($this->tree->parent as $node => $parent) {
            $parent === 0 and $pinned[$node] = true;
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
            $this->open[$i] = $map[$node];
        }
    }

    private function truncatedChild(int $parent): int
    {
        ++$this->report->truncated;
        return $this->truncatedOf[$parent] ??= $this->tree->add($parent, FlatTree::TRUNCATED);
    }
}
