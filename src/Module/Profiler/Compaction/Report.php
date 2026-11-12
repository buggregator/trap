<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Compaction;

/**
 * What the compaction of a call tree did, in nodes taken off the tree.
 *
 * @internal
 */
final class Report
{
    /** Nodes merged into a sibling call of the same function. */
    public int $merged = 0;

    /** Nodes of recursive calls merged into their caller. */
    public int $collapsed = 0;

    /** Nodes of cold subtrees summed in `(other)` nodes. */
    public int $pruned = 0;

    /** Samples or calls summed in `(truncated)` nodes, past the limit of the raw tree. */
    public int $truncated = 0;

    /** Share of the profile in the folded nodes, %: of the time, or the memory, or the counts. */
    public float $folded = 0.0;

    /**
     * @param array<array-key, mixed> $data Metadata value written by {@see self::toArray()}.
     */
    public static function summarize(array $data): string
    {
        $parts = [];
        foreach ([
            'merged' => '%d nodes merged into calls of the same function',
            'collapsed' => '%d nodes of recursion merged into their callers',
            'pruned' => '%d nodes of cold subtrees summed in `(other)`',
            'truncated' => '%d samples or calls summed in `(truncated)`',
        ] as $key => $format) {
            /** @var mixed $value */
            $value = $data[$key] ?? 0;
            \is_int($value) && $value > 0 and $parts[] = \sprintf($format, $value);
        }

        /** @var mixed $folded */
        $folded = $data['folded'] ?? 0.0;
        \is_float($folded) && $folded > 0.0 and $parts[] = "$folded% of the profile in the folded nodes";

        return \implode(', ', $parts);
    }

    public function isEmpty(): bool
    {
        return $this->merged === 0 && $this->collapsed === 0 && $this->pruned === 0 && $this->truncated === 0;
    }

    /**
     * @return array{merged: int, collapsed: int, pruned: int, truncated: int, folded: float}
     */
    public function toArray(): array
    {
        return [
            'merged' => $this->merged,
            'collapsed' => $this->collapsed,
            'pruned' => $this->pruned,
            'truncated' => $this->truncated,
            'folded' => $this->folded,
        ];
    }

    /**
     * Takes the share of the folded nodes of the compacted tree, node 0 holding the roots, in what the compaction
     * ranks the nodes by: see {@see FlatTree::weights()}.
     */
    public function measure(FlatTree $tree): void
    {
        $weights = $tree->weights();
        // Calls of a node alone sum up over all the nodes, not over the roots
        $exclusive = !$tree->inclusiveCounts && \array_sum($tree->value) <= 0.0 && \array_sum($tree->memory) <= 0.0;
        $total = $folded = 0.0;
        foreach ($tree->frame as $node => $frame) {
            ($exclusive ? $node > 0 : $tree->parent[$node] === 0) and $total += $weights[$node];
            ($frame === FlatTree::OTHER || $frame === FlatTree::TRUNCATED) and $folded += $weights[$node];
        }

        $this->folded = $total > 0.0 ? \round($folded / $total * 100.0, 1) : 0.0;
    }
}
