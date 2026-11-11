<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\XDebug;

use Buggregator\Trap\Module\Profiler\Compaction\FlatTree;
use Buggregator\Trap\Module\Profiler\Compaction\NodeBudget;
use Buggregator\Trap\Module\Profiler\Compaction\Nodes;
use Buggregator\Trap\Module\Profiler\Compaction\Report;
use Buggregator\Trap\Module\Profiler\Struct\Branch;
use Buggregator\Trap\Module\Profiler\Struct\Cost;
use Buggregator\Trap\Module\Profiler\Struct\Edge;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Module\Profiler\Struct\Tree;
use Buggregator\Trap\Support\TimeSlice;

/**
 * Builds a profile from Xdebug profiler output in the cachegrind format.
 *
 * Xdebug writes a block per call when the call ends, and the block lists the calls made from it in their order.
 * So the blocks come in post-order, and the exact call tree is restored from them, see {@see CallLinker}.
 *
 * @link https://xdebug.org/docs/profiler
 * @link https://valgrind.org/docs/manual/cl-format.html
 *
 * @psalm-import-type Metadata from Profile
 *
 * @internal
 */
final class ProfileBuilder
{
    /**
     * Size limit of a profile in bytes, both gzipped and decompressed.
     *
     * Xdebug writes a block per call, so its profiles are larger than XHProf ones.
     * Parsing takes about 5 times the decompressed size in memory, and a gzipped profile expands
     * about 10 times, up to hundreds of times when crafted: out of memory, the whole process dies.
     */
    public const MAX_SIZE = 64 * 1024 * 1024;

    /** Bytes of the content split into lines at a time. */
    private const CHUNK = 256 * 1024;

    /**
     * @param positive-int $maxSize Limit of the content size, gzipped and decompressed, in bytes.
     * @param positive-int|null $maxNodes Limit of the tree nodes, over which the tree is compacted;
     *        by default, what the memory left affords when the build starts, see {@see NodeBudget}.
     */
    public function __construct(
        public readonly int $maxSize = self::MAX_SIZE,
        public readonly ?int $maxNodes = null,
    ) {}

    /**
     * @param string $content Cachegrind data, gzipped or not.
     * @param Metadata $metadata Gets `compacted` when the tree was over the node limit: what
     *        {@see Report::toArray()} tells and the `limit`.
     * @param array<non-empty-string, non-empty-string> $tags
     * @param TimeSlice|null $timeSlice Lets other fibers run during a long build.
     *
     * @throws \InvalidArgumentException When the content is not a profile written by Xdebug or is too large.
     */
    public function createProfile(
        string $content,
        \DateTimeInterface $date = new \DateTimeImmutable(),
        array $metadata = [],
        array $tags = [],
        ?TimeSlice $timeSlice = null,
    ): Profile {
        $tooLarge = "the profile is larger than {$this->maxSize} bytes";
        \strlen($content) > $this->maxSize and throw new \InvalidArgumentException(\ucfirst($tooLarge) . '.');

        if (\str_starts_with($content, "\x1f\x8b")) {
            // Fails when the data expands over the limit, but checks it per output chunk, so it may overrun a little
            $content = @\gzdecode($content, $this->maxSize);
            $content === false and throw new \InvalidArgumentException("Invalid gzip data, or $tooLarge.");
            \strlen($content) > $this->maxSize and throw new \InvalidArgumentException(\ucfirst($tooLarge) . '.');
        }

        $linker = $this->link($content, $timeSlice);
        unset($content);
        // The calls read take their memory first
        $limit = $this->maxNodes ?? NodeBudget::nodes();
        $nodes = self::nodes($linker, $limit, $timeSlice);
        $names = $linker->names;
        unset($linker);
        if (!$nodes->report->isEmpty()) {
            $nodes->report->measure($nodes->tree);
            $metadata['compacted'] = $nodes->report->toArray() + ['limit' => $limit];
        }
        $tree = self::buildTree($nodes, $names, $timeSlice);
        unset($nodes);

        /** @var Metadata $metadata */
        return Profile::fromTree($tree, $date, $metadata, $tags, $timeSlice);
    }

    /**
     * Resolves a name compressed as `(id) name` on the first use and `(id)` afterward.
     *
     * @param array<string, non-empty-string> $names Names by their compressed forms: `(id)`.
     *
     * @return non-empty-string
     */
    private static function name(string $value, array &$names): string
    {
        if (isset($names[$value])) {
            return $names[$value];
        }

        if ($value === '' || $value[0] !== '(' || \preg_match('/^\((\d+)\)(?: (.+))?$/', $value, $m) !== 1) {
            $value === '' and throw new \InvalidArgumentException('Empty function name.');
            return $value;
        }

        if (isset($m[2]) && $m[2] !== '') {
            return $names["($m[1])"] = $m[2];
        }

        throw new \InvalidArgumentException("Unknown function name ID `{$m[1]}`.");
    }

    /**
     * @return float Divisor of the time event to microseconds.
     */
    private static function timeDivisor(string $line): float
    {
        $event = \explode(' ', \trim(\substr($line, 7)))[0];

        return match ($event) {
            'Time_(10ns)' => 100.0,
            'Time_(ns)' => 1000.0,
            'Time', 'Time_(µs)' => 1.0,
            default => throw new \InvalidArgumentException("Unsupported time event `$event`."),
        };
    }

    private static function noEvents(): \InvalidArgumentException
    {
        return new \InvalidArgumentException('No `events` line in the profile.');
    }

    /**
     * Puts the calls in a tree, from the roots down: a node per call while they fit the node limit;
     * otherwise the calls of the same function on the same path are merged from the start, and the tree
     * is compacted further when it outgrows the limit.
     *
     * @param positive-int $limit
     */
    private static function nodes(CallLinker $linker, int $limit, ?TimeSlice $timeSlice): Nodes
    {
        $merge = \count($linker->function) > $limit;
        $nodes = new Nodes($limit, \count($linker->names), calls: true, timeSlice: $timeSlice);
        $tree = $nodes->tree;
        /** @var list<int> $path Calls of the path to the current one; their nodes are in `$nodes->open` */
        $path = [];
        foreach ($linker->preOrder() as $call) {
            $caller = $linker->caller[$call];
            while ($path !== [] && $path[\array_key_last($path)] !== $caller) {
                \array_pop($path);
                \array_pop($nodes->open);
            }

            $parent = $nodes->open === [] ? 0 : $nodes->open[\array_key_last($nodes->open)];
            if ($nodes->isFolded($parent)) {
                // The caller's folded node holds the time already
                $node = $parent;
            } else {
                $size = $tree->size();
                $node = $merge ? $nodes->child($parent, $linker->function[$call]) : $nodes->call($parent, $linker->function[$call]);
                $merge && $tree->size() === $size && !$nodes->isFolded($node) and ++$nodes->report->merged;
                $tree->value[$node] += (float) $linker->wt[$call];
                $tree->memory[$node] += (float) $linker->memory[$call];
            }
            ++$tree->count[$node];
            $caller === -1 and $tree->value[0] += (float) $linker->wt[$call];
            $path[] = $call;
            $nodes->open[] = $node;
            $timeSlice?->tick();
        }
        $nodes->open = [];
        $nodes->compact();

        return $nodes;
    }

    /**
     * @param list<non-empty-string> $names Function names by IDs.
     *
     * @return Tree<Edge> Node IDs follow the pre-order; siblings keep the call order.
     */
    private static function buildTree(Nodes $nodes, array $names, ?TimeSlice $timeSlice): Tree
    {
        $flat = $nodes->tree;
        // Children as linked lists, the last child first: popped from a stack, they come in their order.
        // A node is added after its parent.
        $size = $flat->size();
        /** @var array<int, int> $first */
        $first = \array_fill(0, $size, -1);
        /** @var array<int, int> $next */
        $next = \array_fill(0, $size, -1);
        for ($node = 1; $node < $size; ++$node) {
            $parent = $flat->parent[$node];
            $next[$node] = $first[$parent];
            $first[$parent] = $node;
        }

        /** @var Tree<Edge> $tree */
        $tree = new Tree();

        // Iterative: the depth follows the recursion in the profiled code, deeper than Xdebug's nesting limit.
        // Node 0 holds the roots.
        /** @var list<int> $stack */
        $stack = [];
        for ($child = $first[0]; $child !== -1; $child = $next[$child]) {
            $stack[] = $child;
        }
        /** @var list<Branch<Edge>|null> $callers Parent branches of the nodes in the stack */
        $callers = \array_fill(0, \count($stack), null);
        while ($stack !== []) {
            $node = \array_pop($stack);
            $parent = \array_pop($callers);

            $id = 'n' . (\count($tree) + 1);
            $tree->addItem(
                new Edge(
                    caller: $parent?->item->callee,
                    callee: match ($frame = $flat->frame[$node]) {
                        FlatTree::OTHER => Profile::OTHER,
                        FlatTree::TRUNCATED => Profile::TRUNCATED,
                        default => $names[$frame],
                    },
                    cost: new Cost(
                        ct: \max(0, $flat->count[$node]),
                        wt: \max(0, (int) \round($flat->value[$node])),
                        cpu: 0,
                        mu: \max(0, (int) \round($flat->memory[$node])),
                        pmu: 0,
                    ),
                ),
                $id,
                $parent?->id,
            );

            /** @var Branch<Edge> $branch */
            $branch = $tree->all[$id];
            for ($child = $first[$node]; $child !== -1; $child = $next[$child]) {
                $stack[] = $child;
                $callers[] = $branch;
            }
            $timeSlice?->tick();
        }

        return $tree;
    }

    /**
     * Reads the calls and links each to its caller as soon as the caller ends.
     *
     */
    private function link(string $content, ?TimeSlice $timeSlice): CallLinker
    {
        \str_starts_with($content, 'version:') && \str_contains($content, "\ncreator: xdebug")
            or throw new \InvalidArgumentException('Not a cachegrind profile written by Xdebug.');

        /** @var array<string, non-empty-string> $names Function names by their compressed forms */
        $names = [];
        $linker = null;
        $current = null;
        /** @var non-empty-string|null $callee Function of the next cost line, if it describes a call */
        $callee = null;
        $expectCost = false;

        // Split by chunks, not at once: the array of all the lines would take more memory than the calls
        $length = \strlen($content);
        for ($offset = 0; $offset < $length; $offset = $end + 1) {
            $end = $offset + self::CHUNK >= $length ? false : \strpos($content, "\n", $offset + self::CHUNK);
            $end === false and $end = $length;
            foreach (\explode("\n", \substr($content, $offset, $end - $offset)) as $line) {
                if ($line === '') {
                    continue;
                }
                $line[-1] === "\r" and $line = \rtrim($line, "\r");
                if ($line === '') {
                    continue;
                }

                // By the first byte: a line of one kind can't be a cost line, which starts with a digit or a sign
                switch ($line[0]) {
                    case 'e':
                        \str_starts_with($line, 'events:')
                            and $linker ??= new CallLinker(self::timeDivisor($line), $timeSlice);
                        continue 2;
                    case 'f':
                        if (\str_starts_with($line, 'fn=')) {
                            $current === null or ($linker ?? throw self::noEvents())->end($current);
                            $current = new Call(self::name(\substr($line, 3), $names));
                            $expectCost = true;
                            $callee = null;
                        }
                        continue 2;
                    case 'c':
                        if (\str_starts_with($line, 'cfn=')) {
                            $callee = self::name(\substr($line, 4), $names);
                        } elseif (\str_starts_with($line, 'calls=')) {
                            $expectCost = true;
                        }
                        continue 2;
                }

                if (!$expectCost || $current === null) {
                    continue;
                }
                if ($callee === null) {
                    if (\preg_match('/^[+-]?\d+ (-?\d+) (-?\d+)/', $line, $m) === 1) {
                        $expectCost = false;
                        $current->time = (int) $m[1];
                        $current->memory = (int) $m[2];
                    }
                } elseif (\preg_match('/^[+-]?\d+ -?\d+ -?\d+/', $line) === 1) {
                    $expectCost = false;
                    $current->callees[] = $callee;
                    $callee = null;
                }
            }
        }

        $linker ??= throw self::noEvents();
        $current === null or $linker->end($current);
        $linker->finish();

        return $linker;
    }
}
