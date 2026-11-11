<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\Profiler\Internal\Model;

/**
 * Call paths from the roots, each node with the costs spent in it alone (self costs).
 *
 * Nodes are integer ids into flat lists rather than objects: a profile of a large application has
 * hundreds of thousands of nodes, built inside the profiled process. A child always gets a larger id
 * than its parent. Recursion stays a repeated function on the path; the `@N` ids appear only in
 * the {@see CallGraph}.
 *
 * @internal
 */
final class CallTree
{
    public const MAIN = 'main()';

    /** The parent of the roots; costs added to it belong to no function and count in the total only. */
    public const ROOT = -1;

    /** Names of file-scope code that has no file. */
    private const FILE_SCOPE = ['{main}', 'main', self::MAIN, 'Command_line_code', 'Standard_input_code', 'php_shell_code'];

    /** Integer keys below it are packed with the parent id into one integer lookup key. */
    private const KEY_LIMIT = 1 << 24;

    /** An odd number above {@see KEY_LIMIT}. */
    private const KEY_MULTIPLIER = (1 << 24) + 43;

    /** @var list<non-empty-string> */
    private array $names = [];

    /** @var list<string|null> */
    private array $files = [];

    /** @var list<int> */
    private array $parents = [];

    /** @var array<array-key, int> Child ids by a key made of the parent id and the child key. */
    private array $index = [];

    /** @var list<int> Self microseconds by node id. */
    private array $wt = [];

    /** @var list<int> Self samples by node id. */
    private array $samples = [];

    /** @var array<int, int> Self CPU microseconds by node id, only where set. */
    private array $cpu = [];

    /** @var array<int, int> Self memory by node id, only where set. */
    private array $mu = [];

    /** @var array<int, int> Self peak memory by node id, only where set. */
    private array $pmu = [];

    /** @var array<int, int> Self calls by node id, only where set. */
    private array $ct = [];

    /** @var array{int, int, int, int, int, int} wt, cpu, mu, pmu, ct, samples of no function. */
    private array $unattributed = [0, 0, 0, 0, 0, 0];

    /**
     * @param list<Metric> $metrics The metrics the profile measured.
     */
    public function __construct(
        public readonly array $metrics,
    ) {}

    /**
     * Returns the child node, adding it when missing.
     *
     * @param int $parent A node id or {@see ROOT}.
     * @param non-empty-string $name
     * @param array-key|null $key Identifies the child among its siblings; the name by default.
     */
    public function node(int $parent, string $name, ?string $file = null, int|string|null $key = null): int
    {
        $key ??= $name;
        // PHP hashes an integer key by its low bits: an odd multiplier larger than any key mixes both parts into them,
        // where a shift would leave the siblings or the cousins in one bucket chain.
        $lookup = \is_int($key) && $key >= 0 && $key < self::KEY_LIMIT
            ? ($parent + 1) * self::KEY_MULTIPLIER + $key
            : $parent . '/' . $key;
        if (isset($this->index[$lookup])) {
            return $this->index[$lookup];
        }

        $id = \count($this->names);
        $this->names[] = $name;
        $this->files[] = $file;
        $this->parents[] = $parent;
        $this->wt[] = 0;
        $this->samples[] = 0;
        $this->index[$lookup] = $id;

        return $id;
    }

    /**
     * Adds self costs to a node, or to {@see ROOT} for costs of no function.
     *
     * @param int<0, max> $wt Microseconds.
     * @param int<0, max> $cpu Microseconds.
     * @param int<0, max> $mu Bytes.
     * @param int<0, max> $pmu Bytes.
     */
    public function add(int $node, int $wt = 0, int $cpu = 0, int $mu = 0, int $pmu = 0, int $ct = 0, int $samples = 0): void
    {
        if ($node === self::ROOT) {
            $u = $this->unattributed;
            $this->unattributed = [$u[0] + $wt, $u[1] + $cpu, $u[2] + $mu, $u[3] + $pmu, $u[4] + $ct, $u[5] + $samples];
            return;
        }

        $this->wt[$node] += $wt;
        $this->samples[$node] += $samples;
        $cpu === 0 or $this->cpu[$node] = ($this->cpu[$node] ?? 0) + $cpu;
        $mu === 0 or $this->mu[$node] = ($this->mu[$node] ?? 0) + $mu;
        $pmu === 0 or $this->pmu[$node] = ($this->pmu[$node] ?? 0) + $pmu;
        $ct === 0 or $this->ct[$node] = ($this->ct[$node] ?? 0) + $ct;
    }

    /**
     * Folds the paths into caller to callee pairs with inclusive costs, rooted at `main()` as in XHProf.
     *
     * A single root that is file-scope code becomes `main()`; otherwise the roots, a truncated stack's
     * `excimer_truncated` among them, go under a `main()` with the total. Pair costs are summed over
     * the paths: the `@N` suffix keeps a pair from repeating on one path, so nothing is counted twice.
     */
    public function toGraph(): CallGraph
    {
        $count = \count($this->names);
        $parents = $this->parents;
        [$wt, $cpu, $mu, $pmu, $ct, $samples] = [$this->wt, $this->cpu, $this->mu, $this->pmu, $this->ct, $this->samples];
        [$totalWt, $totalCpu, $totalMu, $totalPmu, $totalCt, $totalSamples] = $this->unattributed;

        // Children have larger ids than their parents: one backward pass sums the costs up and links the children,
        // each prepended to its siblings, so that they come out in the order they were added.
        /** @var array<int, int> $first First child id by parent id. */
        $first = [];
        /** @var array<int, int> $next Next sibling id by node id. */
        $next = [];
        for ($id = $count - 1; $id >= 0; --$id) {
            $parent = $parents[$id];
            $next[$id] = $first[$parent] ?? -1;
            $first[$parent] = $id;

            if ($parent === self::ROOT) {
                $totalWt += $wt[$id];
                $totalSamples += $samples[$id];
                $totalCpu += $cpu[$id] ?? 0;
                $totalMu += $mu[$id] ?? 0;
                $totalPmu += $pmu[$id] ?? 0;
                $totalCt += $ct[$id] ?? 0;
                continue;
            }

            $wt[$parent] += $wt[$id];
            $samples[$parent] += $samples[$id];
            isset($cpu[$id]) and $cpu[$parent] = ($cpu[$parent] ?? 0) + $cpu[$id];
            isset($mu[$id]) and $mu[$parent] = ($mu[$parent] ?? 0) + $mu[$id];
            isset($pmu[$id]) and $pmu[$parent] = ($pmu[$parent] ?? 0) + $pmu[$id];
            isset($ct[$id]) and $ct[$parent] = ($ct[$parent] ?? 0) + $ct[$id];
        }

        $graph = new CallGraph($this->metrics);
        $graph->add(null, self::MAIN, $totalWt, $totalCpu, $totalMu, $totalPmu, $totalCt, $totalSamples);

        /** @var array<string, int> $onPath How many times a function is on the current path. */
        $onPath = [self::MAIN => 1];
        $start = $first[self::ROOT] ?? -1;
        if ($start !== -1 && $next[$start] === -1 && $this->isFileScope($start)) {
            $onPath[$this->names[$start]] = 1;
            $start = $first[$start] ?? -1;
        }

        /** @var array<int, non-empty-string> $callers Caller ids of the nodes waiting on the stack. */
        $callers = [];
        // Iterative: the depth follows the recursion in the profiled code. A negative entry `~id` leaves the node.
        $stack = [];
        $siblings = [];
        for ($id = $start; $id !== -1; $id = $next[$id]) {
            $siblings[] = $id;
            $callers[$id] = self::MAIN;
        }
        for ($i = \count($siblings) - 1; $i >= 0; --$i) {
            $stack[] = $siblings[$i];
        }

        $names = $this->names;
        while ($stack !== []) {
            $id = \array_pop($stack);
            if ($id < 0) {
                /** @var int<0, max> $left */
                $left = ~$id;
                --$onPath[$names[$left]];
                continue;
            }

            $name = $names[$id];
            $level = $onPath[$name] ?? 0;
            $callee = $level === 0 ? $name : "{$name}@{$level}";
            $graph->add(
                $callers[$id],
                $callee,
                $wt[$id],
                $cpu[$id] ?? 0,
                $mu[$id] ?? 0,
                $pmu[$id] ?? 0,
                $ct[$id] ?? 0,
                $samples[$id],
            );
            unset($callers[$id]);

            $onPath[$name] = $level + 1;
            $stack[] = ~$id;
            $siblings = [];
            for ($child = $first[$id] ?? -1; $child !== -1; $child = $next[$child]) {
                $siblings[] = $child;
                $callers[$child] = $callee;
            }
            for ($i = \count($siblings) - 1; $i >= 0; --$i) {
                $stack[] = $siblings[$i];
            }
        }

        return $graph;
    }

    /**
     * Excimer names file-scope code by its file, with spaces and NUL bytes turned into `_`.
     */
    private function isFileScope(int $id): bool
    {
        $name = $this->names[$id];
        $file = $this->files[$id];

        return \in_array($name, self::FILE_SCOPE, true)
            || ($file !== null && $name === \strtr($file, [' ' => '_', "\0" => '_']));
    }
}
