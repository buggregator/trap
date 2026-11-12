<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Compaction;

/**
 * Call tree in flat arrays indexed by node, as the builders read it: an object per node with a map of its
 * children would take six times the memory.
 *
 * A frame is a function ID, or a negative marker. Values are inclusive.
 *
 * @internal
 */
final class FlatTree
{
    /** Frame of a node that sums the children pruned from its parent. */
    public const OTHER = -3;

    /** Frame of a node that sums what came over the node limit. */
    public const TRUNCATED = -2;

    /** @var array<int, int> Function IDs, or negative markers */
    public array $frame = [];

    /** @var array<int, int> Parent indexes; -1 for a root */
    public array $parent = [];

    /** @var array<int, float> Inclusive time in microseconds, or another value of the profile */
    public array $value = [];

    /** @var array<int, float> Inclusive memory in bytes */
    public array $memory = [];

    /** @var array<int, float> Inclusive CPU time in microseconds, when `value` holds the wall time */
    public array $cpu = [];

    /** @var array<int, int> Samples with the node on the stack, or calls */
    public array $count = [];

    /**
     * @param bool $inclusiveCounts A count is the samples the node is on the stack in, so it includes the counts
     *        of the children; otherwise it is the calls of the node only.
     */
    public function __construct(
        public readonly bool $inclusiveCounts,
    ) {}

    /**
     * @param int $parent A node added before, or -1 for a root: the compaction reads the depths in one pass.
     */
    public function add(
        int $parent,
        int $frame,
        float $value = 0.0,
        int $count = 0,
        float $memory = 0.0,
        float $cpu = 0.0,
    ): int {
        $this->frame[] = $frame;
        $this->parent[] = $parent;
        $this->value[] = $value;
        $this->memory[] = $memory;
        $this->cpu[] = $cpu;
        $this->count[] = $count;

        return \count($this->frame) - 1;
    }

    /**
     * @return int<0, max>
     */
    public function size(): int
    {
        return \count($this->frame);
    }

    /**
     * @return array<int, float> What the nodes weigh by index: the time, or the memory in a profile of no time,
     *         as a heap profile, or else the counts.
     */
    public function weights(): array
    {
        return match (true) {
            \array_sum($this->value) > 0.0 => $this->value,
            \array_sum($this->memory) > 0.0 => $this->memory,
            default => \array_map('floatval', $this->count),
        };
    }

    /**
     * @return array<int, list<int>> Children by parents in the order they were added; roots under -1.
     */
    public function children(): array
    {
        $children = [];
        foreach ($this->parent as $node => $parent) {
            $children[$parent][] = $node;
        }

        return $children;
    }
}
