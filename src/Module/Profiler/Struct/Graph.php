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
     * Unfolds the graph into a call tree from the roots.
     *
     * The tree is approximate: a function called from several callers is unfolded under each of them,
     * with its callees scaled by the share of the caller in the function's total cost.
     * Percentages are scaled the same way; deltas are against the parent node of the tree.
     *
     * @param float $minShare Nodes with a smaller share of the roots' wall time are dropped:
     *        unfolding multiplies the nodes of shared functions. Of the call counts when the roots have no wall time.
     * @param int<1, max> $maxNodes Past it, the tree is {@see Tree::$cut} to the heaviest nodes that fit, in the
     *        same order: shared functions calling each other unfold into exponentially many nodes.
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
        if ($total === 0) {
            $metric = Metric::Count;
            foreach ($this->roots as $edge) {
                $total += $edge->cost->ct;
            }
        }

        $minValue = (float) $total * $minShare;
        $tree = $this->unfoldFrom($metric, $minValue, null, $maxNodes, $timeSlice);
        if (!$tree->cut) {
            return $tree;
        }

        // Cut in the order of the calls, the tree would keep the light calls made first and lose the heavy ones
        // made after them: unfolded again, it keeps only the nodes as heavy as the heaviest ones that fit
        unset($tree);
        [$least, $ties] = $this->leastOfHeaviest($metric, $minValue, $maxNodes, $timeSlice);
        $tree = $this->unfoldFrom($metric, $least, $ties, $maxNodes, $timeSlice);
        $tree->cut = true;

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
     * Unfolds the heaviest nodes first, as many as the tree takes.
     *
     * The cycles in received data are not followed: the nodes are counted without their ancestors, so a cycle
     * just takes the place of lighter nodes.
     *
     * @param int<1, max> $maxNodes
     *
     * @return array{float, int<0, max>} The value of the lightest node unfolded, and how many nodes of this value
     *         fit beside the heavier ones.
     */
    private function leastOfHeaviest(Metric $metric, float $minValue, int $maxNodes, ?TimeSlice $timeSlice): array
    {
        $queue = new \SplPriorityQueue();
        foreach ($this->roots as $edge) {
            $queue->insert([$edge, 1.0], (float) $metric->of($edge->cost));
        }

        $least = \INF;
        $unfolded = $leastCount = 0;
        while ($unfolded < $maxNodes && !$queue->isEmpty()) {
            /** @var array{Edge, float} $entry */
            $entry = $queue->extract();
            [$edge, $scale] = $entry;
            // The same arithmetic as in `self::unfoldFrom()`, so the values compare equal
            $value = (float) $metric->of($edge->cost) * $scale;
            if ($value < $minValue) {
                continue;
            }

            ++$unfolded;
            if ($value < $least) {
                $least = $value;
                $leastCount = 0;
            }
            $value === $least and ++$leastCount;

            $calleeTotal = $metric->of($this->inclusiveCost($edge->calleeId));
            $childScale = $calleeTotal > 0 ? $value / (float) $calleeTotal : $scale;
            foreach ($this->callees($edge->calleeId) as $child) {
                $queue->insert([$child, $childScale], (float) $metric->of($child->cost) * $childScale);
            }
            $timeSlice?->tick();
        }

        return [$least, \max(0, $maxNodes - ($unfolded - $leastCount))];
    }

    /**
     * @param float $minValue Nodes with a smaller value are dropped.
     * @param int<0, max>|null $ties Nodes of exactly `$minValue` to keep at most; null for all.
     * @param int<1, max> $maxNodes
     *
     * @return Tree<Edge>
     */
    private function unfoldFrom(Metric $metric, float $minValue, ?int $ties, int $maxNodes, ?TimeSlice $timeSlice): Tree
    {
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
            $value = (float) $metric->of($edge->cost) * $scale;
            if ($value < $minValue || isset($path[$edge->calleeId])) {
                continue;
            }

            if ($ties !== null && $value === $minValue) {
                if ($ties === 0) {
                    continue;
                }
                --$ties;
            }

            if (\count($tree) >= $maxNodes) {
                $tree->cut = true;
                break;
            }

            $timeSlice?->tick();
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
            foreach (\array_reverse($this->callees($edge->calleeId)) as $child) {
                $stack[] = [$child, $branch, $childScale];
            }
        }

        return $tree;
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
