<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Compaction;

use Buggregator\Trap\Module\Profiler\Struct\Branch;
use Buggregator\Trap\Module\Profiler\Struct\Cost;
use Buggregator\Trap\Module\Profiler\Struct\Edge;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Module\Profiler\Struct\Tree;

/**
 * Turns the merged stacks of a sampling profiler, or the calls of an evented one, read into {@see Nodes},
 * into the call tree of a profile.
 *
 * A single root that is file-scope code becomes `main()`, as an XHProf root, and takes the samples with
 * an empty stack. Otherwise the roots of stacks that start at different frames, and samples with an empty stack,
 * go under a synthetic `main()`.
 *
 * @internal
 */
final class StackTree
{
    /** Name of the root over several roots or file-scope code, as XHProf names its root. */
    public const ROOT = 'main()';

    /** Name of a frame that has none. */
    public const UNKNOWN = '(unknown)';

    /**
     * Names of file-scope code besides file paths: Xdebug's, a `main` frame, Excimer's for `php -r`,
     * and the Datadog profiler's.
     */
    private const FILE_SCOPE = [
        self::ROOT, '{main}', 'main', 'Command_line_code', 'Standard_input_code', 'php_shell_code', '<?php',
    ];

    /**
     * @param list<non-empty-string> $names Function names by frames.
     * @param array<non-empty-string, true> $fileScope Names of the frames of file-scope code.
     *
     * @return array{int, non-empty-string}|null The root node and its name, or null when the profile is empty.
     *         Several roots, and samples with an empty stack, which belong to no root, go under node 0.
     */
    public static function root(Nodes $nodes, array $names, array $fileScope = [], bool $evented = false): ?array
    {
        $tree = $nodes->tree;
        $roots = $nodes->roots();
        if (\count($roots) === 1) {
            $root = $roots[0];
            $name = self::nodeName($nodes, $names, $root);
            if (\in_array($name, self::FILE_SCOPE, true) || isset($fileScope[$name])) {
                // The script takes the samples with an empty stack as its own time
                if ($nodes->emptyStacks > 0) {
                    $tree->value[$root] = $tree->value[0];
                    $tree->memory[$root] = $tree->memory[0];
                    $tree->cpu[$root] = $tree->cpu[0];
                    $tree->count[$root] = $tree->count[0];
                }
                return [$root, self::ROOT];
            }
            if ($nodes->emptyStacks === 0) {
                return [$root, $name];
            }
        }

        if ($roots === [] && $nodes->emptyStacks === 0) {
            return null;
        }

        // Node 0 sums the sampled stacks; an evented `main()` is a call that spans its children
        if ($evented) {
            $tree->count[0] = 1;
            foreach ($roots as $node) {
                $tree->value[0] += $tree->value[$node];
            }
        }

        return [0, self::ROOT];
    }

    /**
     * Rounds the values to integers, keeping the sums of caller-callee pairs exact as the Xdebug builder does:
     * the rounding error of a pair is carried over to its next node, and a node never takes less than its children.
     *
     * @param int $root Node 0 or the single root: all the nodes after it are its descendants.
     * @param non-empty-string $rootName
     * @param list<non-empty-string> $names Function names by frames.
     * @param array<'wt'|'cpu'|'mu', 'value'|'memory'|'cpu'> $metrics The {@see FlatTree} sums the costs take:
     *        a sum may go to several costs, as the CPU time to `wt` too when the wall time is not measured.
     *
     * @return Tree<Edge> Node IDs follow the pre-order: `n1` is the root.
     */
    public static function build(Nodes $nodes, int $root, string $rootName, array $names, array $metrics): Tree
    {
        $tree = $nodes->tree;
        $frameOf = $tree->frame;
        $parentOf = $tree->parent;

        // Names by IDs: a pair of names is keyed by an integer, not by a string built per node
        $nameIds = \array_flip($names);
        foreach ([Profile::TRUNCATED, Profile::OTHER, self::ROOT, $rootName] as $marker) {
            isset($nameIds[$marker]) or $nameIds[$marker] = \array_push($names, $marker) - 1;
        }
        /** @var array<int, int> $markerIds */
        $markerIds = [
            FlatTree::TRUNCATED => $nameIds[Profile::TRUNCATED],
            FlatTree::OTHER => $nameIds[Profile::OTHER],
            Nodes::TOP => $nameIds[self::ROOT],
        ];
        $rootId = $nameIds[$rootName];
        unset($nameIds);

        // A node is added after its parent: in the reversed order the children come before their parents
        $last = $tree->size() - 1;
        /** @var array<int, int> $nameOf */
        $nameOf = \array_fill(0, $last + 1, 0);
        for ($node = $root; $node <= $last; ++$node) {
            $frame = $frameOf[$node];
            $nameOf[$node] = $frame >= 0 ? $frame : $markerIds[$frame];
        }
        $nameOf[$root] = $rootId;

        /** @var array<'value'|'memory'|'cpu', array<int, int<0, max>>> $rounded */
        $rounded = [];
        foreach (\array_unique($metrics) as $sum) {
            $values = match ($sum) {
                'value' => $tree->value,
                'memory' => $tree->memory,
                'cpu' => $tree->cpu,
            };
            $rounded[$sum] = self::round($nodes, $values, $root, $nameOf, \count($names));
            unset($values);
        }
        $zeros = \array_fill(0, $last + 1, 0);
        $wt = isset($metrics['wt']) ? $rounded[$metrics['wt']] : $zeros;
        $cpu = isset($metrics['cpu']) ? $rounded[$metrics['cpu']] : $zeros;
        $mu = isset($metrics['mu']) ? $rounded[$metrics['mu']] : $zeros;
        unset($rounded, $zeros);

        // Children as linked lists, the last child first: popped from a stack, they come in their order
        /** @var array<int, int> $first */
        $first = \array_fill(0, $last + 1, -1);
        /** @var array<int, int> $next */
        $next = \array_fill(0, $last + 1, -1);
        for ($node = $root + 1; $node <= $last; ++$node) {
            $parent = $parentOf[$node];
            $next[$node] = $first[$parent];
            $first[$parent] = $node;
        }

        /** @var Tree<Edge> $result */
        $result = new Tree();
        $count = $tree->count;
        /** @var list<int> $stack */
        $stack = [$root];
        /** @var list<Branch<Edge>|null> $callers Parent branches of the nodes in the stack */
        $callers = [null];
        while ($stack !== []) {
            $node = \array_pop($stack);
            $parent = \array_pop($callers);

            $id = 'n' . (\count($result) + 1);
            $result->addItem(
                new Edge(
                    caller: $parent?->item->callee,
                    callee: $names[$nameOf[$node]],
                    cost: new Cost(
                        ct: \max(0, $count[$node]),
                        wt: $wt[$node],
                        cpu: $cpu[$node],
                        mu: $mu[$node],
                        pmu: 0,
                    ),
                ),
                $id,
                $parent?->id,
            );

            /** @var Branch<Edge> $branch */
            $branch = $result->all[$id];
            for ($child = $first[$node]; $child !== -1; $child = $next[$child]) {
                $stack[] = $child;
                $callers[] = $branch;
            }
            $nodes->timeSlice?->tick();
        }

        return $result;
    }

    /**
     * @param array<int, float> $valueOf
     * @param array<int, int> $nameOf Name IDs by nodes.
     * @param int<0, max> $nameCount
     *
     * @return array<int, int<0, max>> Rounded values by nodes; 0 before the root.
     */
    private static function round(Nodes $nodes, array $valueOf, int $root, array $nameOf, int $nameCount): array
    {
        $parentOf = $nodes->tree->parent;
        $last = \count($parentOf) - 1;
        // The ID that stands for no caller
        $none = $nameCount;
        $span = $none + 1;

        /** @var array<int, int<0, max>> $rounded */
        $rounded = \array_fill(0, $last + 1, 0);
        /** @var array<int, int<0, max>> $childrenSum */
        $childrenSum = \array_fill(0, $last + 1, 0);
        /** @var array<int, float> $pairValue */
        $pairValue = [];
        /** @var array<int, int<0, max>> $pairRounded */
        $pairRounded = [];
        for ($node = $last; $node >= $root; --$node) {
            $parent = $parentOf[$node];
            $pair = ($node === $root ? $none : $nameOf[$parent]) * $span + $nameOf[$node];
            $pairValue[$pair] = ($pairValue[$pair] ?? 0.0) + $valueOf[$node];
            $given = $pairRounded[$pair] ?? 0;
            $value = \max($childrenSum[$node], (int) \round($pairValue[$pair]) - $given);
            $pairRounded[$pair] = $given + $value;
            $rounded[$node] = $value;
            $node === $root or $childrenSum[$parent] += $value;
            $nodes->timeSlice?->tick();
        }

        return $rounded;
    }

    /**
     * @param list<non-empty-string> $names
     *
     * @return non-empty-string
     */
    private static function nodeName(Nodes $nodes, array $names, int $node): string
    {
        return match ($frame = $nodes->tree->frame[$node]) {
            FlatTree::TRUNCATED => Profile::TRUNCATED,
            FlatTree::OTHER => Profile::OTHER,
            Nodes::TOP => self::ROOT,
            default => $names[$frame],
        };
    }
}
