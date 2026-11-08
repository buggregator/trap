<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Struct;

/**
 * Call graph of a profile: functions are nodes, {@see Edge}s link callers to callees.
 *
 * A profiler like XHProf sums the calls by caller and callee pairs, so a call tree can't be restored
 * from its data: when `x()` is called from both `a()` and `b()`, the callees of `x()` belong to both.
 *
 * @implements \IteratorAggregate<non-empty-string, Edge>
 *
 * @internal
 */
final class Graph implements \IteratorAggregate, \Countable
{
    /** @var array<non-empty-string, Edge> Edges by `callerId==>calleeId` keys */
    private array $edges = [];

    /** @var array<non-empty-string, list<Edge>> Edges by caller IDs */
    private array $outgoing = [];

    /** @var array<non-empty-string, list<Edge>> Edges by callee IDs */
    private array $incoming = [];

    /** @var list<Edge> */
    private array $roots = [];

    /** @var array<string, Cost> Inclusive costs by function IDs */
    private array $inclusiveCosts = [];

    public function add(Edge $edge): void
    {
        $key = ($edge->callerId ?? '') . '==>' . $edge->calleeId;
        \array_key_exists($key, $this->edges) and throw new \LogicException("Duplicate edge `$key`.");

        $this->edges[$key] = $edge;
        unset($this->inclusiveCosts[$edge->calleeId]);
        $this->incoming[$edge->calleeId][] = $edge;
        $edge->callerId === null
            ? $this->roots[] = $edge
            : $this->outgoing[$edge->callerId][] = $edge;
    }

    /**
     * @return list<Edge> Edges without a caller.
     */
    public function roots(): array
    {
        return $this->roots;
    }

    /**
     * @return list<Edge> Calls made from the function.
     */
    public function callees(string $functionId): array
    {
        return $this->outgoing[$functionId] ?? [];
    }

    /**
     * @return list<Edge> Calls of the function.
     */
    public function callers(string $functionId): array
    {
        return $this->incoming[$functionId] ?? [];
    }

    /**
     * @return list<non-empty-string> IDs of the called functions.
     */
    public function functions(): array
    {
        return \array_keys($this->incoming);
    }

    /**
     * Total cost of all the calls of the function, its callees included.
     */
    public function inclusiveCost(string $functionId): Cost
    {
        if (isset($this->inclusiveCosts[$functionId])) {
            return $this->inclusiveCosts[$functionId];
        }

        $ct = $wt = $cpu = $mu = $pmu = 0;
        foreach ($this->callers($functionId) as $edge) {
            $ct += $edge->cost->ct;
            $wt += $edge->cost->wt;
            $cpu += $edge->cost->cpu;
            $mu += $edge->cost->mu;
            $pmu += $edge->cost->pmu;
        }

        return $this->inclusiveCosts[$functionId] = new Cost($ct, $wt, $cpu, \max(0, $mu), \max(0, $pmu));
    }

    /**
     * Unfolds the graph into a call tree from the roots.
     *
     * The tree is approximate: a function called from several callers is unfolded under each of them,
     * with its callees scaled by the share of the caller in the function's total cost.
     * Percentages are scaled the same way; deltas are against the parent node of the tree.
     *
     * @param float $minShare Nodes with a smaller share of the roots' wall time are dropped:
     *        unfolding multiplies the nodes of shared functions.
     *
     * @return Tree<Edge> Node IDs are `n1`, `n2`, etc.
     */
    public function unfold(float $minShare = 0.0): Tree
    {
        $total = 0;
        foreach ($this->roots as $edge) {
            $total += $edge->cost->wt;
        }

        $minWt = (float) $total * $minShare;

        /** @var Tree<Edge> $tree */
        $tree = new Tree();
        /** @var array<non-empty-string, true> $path Function IDs of the ancestors, to stop on a cycle */
        $path = [];

        // Iterative: the depth follows the recursion in the profiled code, deeper than Xdebug's nesting limit.
        // An entry is an edge to unfold with its parent node and scale, or a function ID that leaves the path.
        /** @var list<array{Edge, Branch<Edge>|null, float}|non-empty-string> $stack */
        $stack = \array_map(static fn(Edge $edge): array => [$edge, null, 1.0], \array_reverse($this->roots));
        while ($stack !== []) {
            $entry = \array_pop($stack);
            if (\is_string($entry)) {
                unset($path[$entry]);
                continue;
            }

            [$edge, $parent, $scale] = $entry;
            $wt = (float) $edge->cost->wt * $scale;
            if ($wt < $minWt || isset($path[$edge->calleeId])) {
                continue;
            }

            $id = 'n' . (\count($tree) + 1);
            $tree->addItem(
                new Edge(
                    $edge->caller,
                    $edge->callee,
                    self::scaleCost($edge->cost, $scale, $parent?->item->cost),
                    $edge->calleeId,
                    $edge->callerId,
                ),
                $id,
                $parent?->id,
            );
            /** @var Branch<Edge> $branch */
            $branch = $tree->all[$id];

            $calleeTotal = $this->inclusiveCost($edge->calleeId)->wt;
            $childScale = $calleeTotal > 0 ? $wt / (float) $calleeTotal : $scale;

            $path[$edge->calleeId] = true;
            $stack[] = $edge->calleeId;
            foreach (\array_reverse($this->callees($edge->calleeId)) as $child) {
                $stack[] = [$child, $branch, $childScale];
            }
        }

        return $tree;
    }

    /**
     * @return \Traversable<non-empty-string, Edge> Edges by `callerId==>calleeId` keys in the order of adding.
     */
    #[\Override]
    public function getIterator(): \Traversable
    {
        yield from $this->edges;
    }

    /**
     * @return int<0, max> Number of edges.
     */
    #[\Override]
    public function count(): int
    {
        return \count($this->edges);
    }

    private static function scaleCost(Cost $cost, float $scale, ?Cost $parent): Cost
    {
        $result = new Cost(
            self::scaleValue($cost->ct, $scale),
            self::scaleValue($cost->wt, $scale),
            self::scaleValue($cost->cpu, $scale),
            self::scaleValue($cost->mu, $scale),
            self::scaleValue($cost->pmu, $scale),
        );
        $result->p_ct = \round($cost->p_ct * $scale, 3);
        $result->p_wt = \round($cost->p_wt * $scale, 3);
        $result->p_cpu = \round($cost->p_cpu * $scale, 3);
        $result->p_mu = \round($cost->p_mu * $scale, 3);
        $result->p_pmu = \round($cost->p_pmu * $scale, 3);

        if ($parent !== null) {
            $result->d_ct = $result->ct - $parent->ct;
            $result->d_wt = $result->wt - $parent->wt;
            $result->d_cpu = $result->cpu - $parent->cpu;
            $result->d_mu = $result->mu - $parent->mu;
            $result->d_pmu = $result->pmu - $parent->pmu;
        }

        return $result;
    }

    /**
     * @param int<0, max> $value
     *
     * @return int<0, max>
     */
    private static function scaleValue(int $value, float $scale): int
    {
        return \max(0, (int) \round((float) $value * $scale));
    }
}
