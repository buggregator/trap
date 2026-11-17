<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Struct;

use Buggregator\Trap\Support\TimeSlice;

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

    /**
     * Sums the nodes of an exact call tree by caller and callee pairs.
     *
     * @param Tree<Edge> $tree Items carry function IDs with recursion levels, so the graph has no cycles.
     * @param TimeSlice|null $timeSlice Lets other fibers run during a long build.
     *
     * @return self Edges without percentages and deltas: see {@see self::setPercentsAndDeltas()}.
     */
    public static function fromTree(Tree $tree, ?TimeSlice $timeSlice = null): self
    {
        /** @var array<non-empty-string, array{Edge, int, int, int, int, int}> $sums Edges with summed metrics */
        $sums = [];
        foreach ($tree->all as $branch) {
            $edge = $branch->item;
            $cost = $edge->cost;
            $key = ($edge->callerId ?? '') . '==>' . $edge->calleeId;
            // Summed in place: a new array per node would be allocated and the old one freed
            if (isset($sums[$key])) {
                $sums[$key][1] += $cost->ct;
                $sums[$key][2] += $cost->wt;
                $sums[$key][3] += $cost->cpu;
                $sums[$key][4] += $cost->mu;
                $sums[$key][5] += $cost->pmu;
            } else {
                $sums[$key] = [$edge, $cost->ct, $cost->wt, $cost->cpu, $cost->mu, $cost->pmu];
            }
            $timeSlice?->tick();
        }

        $graph = new self();
        // By reference, to free the sums as the edges are made
        foreach ($sums as $key => &$sum) {
            [$edge, $ct, $wt, $cpu, $mu, $pmu] = $sum;
            $sum = null;
            $graph->addKeyed($key, new Edge(
                $edge->caller,
                $edge->callee,
                new Cost(\max(0, $ct), \max(0, $wt), \max(0, $cpu), \max(0, $mu), \max(0, $pmu)),
                $edge->calleeId,
                $edge->callerId,
            ));
            $timeSlice?->tick();
        }
        unset($sum, $sums);

        return $graph;
    }

    /**
     * Adds a root for every caller that is never called, as when profiling starts deep in the stack.
     *
     * The root's cost sums the times of its calls and takes the maximum of their memory.
     */
    public function addMissingRoots(): void
    {
        /** @var array<non-empty-string, array{non-empty-string, Peaks}> $missing Names and costs by caller IDs */
        $missing = [];
        foreach ($this->edges as $edge) {
            if ($edge->callerId === null || $edge->caller === null || $this->callers($edge->callerId) !== []) {
                continue;
            }

            $missing[$edge->callerId] ??= [$edge->caller, new Peaks()];
            $missing[$edge->callerId][1]->add($edge->cost);
        }

        foreach ($missing as $callerId => [$name, $cost]) {
            $this->add(new Edge(caller: null, callee: $name, cost: $cost->toCost(), calleeId: $callerId));
        }
    }

    /**
     * Sets the shares of the peaks and the deltas against the callers' total cost of every edge.
     */
    public function setPercentsAndDeltas(Peaks $peaks): void
    {
        foreach ($this->edges as $edge) {
            $edge->cost->setPercentsOf($peaks);
            $edge->callerId === null or $edge->cost->setDeltasTo($this->inclusiveCost($edge->callerId));
        }
    }

    public function add(Edge $edge): void
    {
        $key = ($edge->callerId ?? '') . '==>' . $edge->calleeId;
        \array_key_exists($key, $this->edges) and throw new \LogicException("Duplicate edge `$key`.");

        $this->addKeyed($key, $edge);
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
     * Follows the heaviest call from the heaviest root down to a leaf; of equally heavy calls, the first one.
     *
     * @return list<Edge> Empty when no root has a cost of the metric.
     */
    public function hotPath(Metric $metric): array
    {
        $path = [];
        /** @var array<non-empty-string, true> $visited */
        $visited = [];
        $edge = self::heaviest($this->roots, $metric);
        // The function IDs carry recursion levels, so a profiler's graph has no cycles; received data may have them
        while ($edge !== null && !isset($visited[$edge->calleeId])) {
            $visited[$edge->calleeId] = true;
            $path[] = $edge;
            $edge = self::heaviest($this->callees($edge->calleeId), $metric);
        }

        return $path;
    }

    /**
     * Unfolds the graph into a call tree from the roots, depth first in the order of the calls.
     *
     * The tree is approximate: a function called from several callers is unfolded under each of them,
     * with its callees scaled by the share of the caller in the function's total cost.
     * Percentages are scaled the same way; deltas are against the parent node of the tree.
     *
     * Only when the roots have no wall time, the scales and `$minShare` go by the call counts instead:
     * scaled by a zero wall time, every node would keep the full cost of its callees, and shared functions
     * calling each other would unfold into exponentially many nodes. A root added for a profile without `main()`
     * has no calls, so it counts the calls of its callees.
     *
     * @param float $minShare Nodes with a smaller share of the roots' wall time are dropped:
     *        unfolding multiplies the nodes of shared functions.
     * @param int<1, max> $maxNodes Past it, the tree is {@see Tree::$cut}: it keeps the calls in the order they
     *        were made up to the limit and leaves out the later ones, however heavy.
     * @param TimeSlice|null $timeSlice Lets other fibers run during a long unfolding.
     *
     * @return Tree<Edge> Node IDs are `n1`, `n2`, etc.
     */
    public function unfold(float $minShare = 0.0, int $maxNodes = \PHP_INT_MAX, ?TimeSlice $timeSlice = null): Tree
    {
        $metric = Metric::WallTime;
        $total = 0;
        foreach ($this->roots as $edge) {
            $total += $edge->cost->wt;
        }
        /** @var array<non-empty-string, int> $rootCounts Counts of the roots with no calls of their own, by IDs */
        $rootCounts = [];
        if ($total === 0) {
            $metric = Metric::Count;
            foreach ($this->roots as $edge) {
                // A root added for a profile without `main()` has no calls: its callees have them
                if ($edge->cost->ct === 0) {
                    $count = 0;
                    foreach ($this->callees($edge->calleeId) as $child) {
                        $count += $child->cost->ct;
                    }
                    $rootCounts[$edge->calleeId] = $count;
                }
                $total += $rootCounts[$edge->calleeId] ?? $edge->cost->ct;
            }
        }

        $minValue = (float) $total * $minShare;

        /** @var Tree<Edge> $tree */
        $tree = new Tree();
        /** @var array<non-empty-string, true> $path Function IDs of the ancestors, to stop on a cycle */
        $path = [];
        /** @var array<non-empty-string, array{list<float>, list<int<0, max>>}> $byValue See {@see self::byValue()} */
        $byValue = [];

        // Iterative: the depth follows the recursion in the profiled code, deeper than Xdebug's nesting limit.
        // An entry is an edge to unfold with its parent node and scale, or a function ID that leaves the path.
        /** @var list<array{Edge, Branch<Edge>|null, float}|non-empty-string> $stack */
        $stack = \array_map(static fn(Edge $edge): array => [$edge, null, 1.0], \array_reverse($this->roots));
        while ($stack !== []) {
            $timeSlice?->tick();
            $entry = \array_pop($stack);
            if (\is_string($entry)) {
                unset($path[$entry]);
                continue;
            }

            [$edge, $parent, $scale] = $entry;
            $value = $parent === null && isset($rootCounts[$edge->calleeId])
                ? (float) $rootCounts[$edge->calleeId]
                : (float) $metric->of($edge->cost) * $scale;
            if ($value < $minValue || isset($path[$edge->calleeId])) {
                continue;
            }

            if (\count($tree) >= $maxNodes) {
                $tree->cut = true;
                break;
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

            $calleeTotal = $metric->of($this->inclusiveCost($edge->calleeId));
            $childScale = $calleeTotal > 0 ? $value / (float) $calleeTotal : $scale;

            $path[$edge->calleeId] = true;
            $stack[] = $edge->calleeId;
            $callees = $this->callees($edge->calleeId);
            if ($callees === []) {
                continue;
            }

            // Only the callees that pass `$minValue`: a function may call thousands, most of them too light.
            // The check above, negated: a zero value at an INF scale is NaN, which it keeps and `<=` would drop.
            // A negative scale, from negative call counts in received data, reverses the order: the check above
            // then takes every callee itself.
            [$values, $order] = $byValue[$edge->calleeId] ??= self::byValue($callees, $metric);
            $low = 0;
            $high = \count($values);
            while ($childScale >= 0.0 && $low < $high) {
                $middle = ($low + $high) >> 1;
                $minValue > $values[$middle] * $childScale ? $high = $middle : $low = $middle + 1;
            }
            if ($childScale >= 0.0 && $low < \count($values)) {
                $passing = \array_slice($order, 0, $low);
                \sort($passing);
                $callees = \array_map(static fn(int $i): Edge => $callees[$i], $passing);
            }
            for ($i = \count($callees) - 1; $i >= 0; --$i) {
                $stack[] = [$callees[$i], $branch, $childScale];
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

    /**
     * @param list<Edge> $edges
     */
    private static function heaviest(array $edges, Metric $metric): ?Edge
    {
        $result = null;
        foreach ($edges as $edge) {
            $value = $metric->of($edge->cost);
            $value > 0 && ($result === null || $value > $metric->of($result->cost))
                and $result = $edge;
        }

        return $result;
    }

    /**
     * @param non-empty-list<Edge> $edges
     *
     * @return array{list<float>, list<int<0, max>>} The values of the metric, largest first, and the indexes
     *         of their edges.
     */
    private static function byValue(array $edges, Metric $metric): array
    {
        $values = [];
        foreach ($edges as $edge) {
            $values[] = (float) $metric->of($edge->cost);
        }
        $order = \array_keys($values);
        \array_multisort($values, \SORT_DESC, \SORT_NUMERIC, $order);

        return [$values, $order];
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

    /**
     * @param non-empty-string $key The edge's key, not taken.
     */
    private function addKeyed(string $key, Edge $edge): void
    {
        $this->edges[$key] = $edge;
        unset($this->inclusiveCosts[$edge->calleeId]);
        $this->incoming[$edge->calleeId][] = $edge;
        $edge->callerId === null
            ? $this->roots[] = $edge
            : $this->outgoing[$edge->callerId][] = $edge;
    }
}
