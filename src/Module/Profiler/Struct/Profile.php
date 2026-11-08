<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Struct;

use Buggregator\Trap\Support\Uuid;

/**
 * @psalm-type Metadata = array{
 *     app_name?: string,
 *     hostname?: string,
 *     filename?: string,
 *     filesize?: int<0, max>,
 *     ...
 * }
 *
 * @psalm-type ProfileData = array{
 *     date: int,
 *     app_name?: string,
 *     hostname?: string,
 *     filename?: string,
 *     tags: array<non-empty-string, non-empty-string>,
 *     peaks: PeaksData|Peaks,
 *     edges: array,
 *     total_edges: int<0, max>,
 * }
 *
 * @psalm-type ProfileInput = array{
 *     date: int,
 *     tags: array<non-empty-string, non-empty-string>,
 *     peaks: PeaksData,
 *     ...
 * }
 *
 * @psalm-import-type PeaksData from Peaks
 *
 * @internal
 */
final class Profile implements \JsonSerializable
{
    /**
     * Unfolded nodes with a smaller share of the profile are dropped: they are too small to show.
     */
    private const UNFOLD_MIN_SHARE = 0.0001;

    public Peaks $peaks;
    public Graph $calls;

    /** @var non-empty-string some leaked bs required for Frontend */
    public string $uuid;

    /**
     * @param Metadata $metadata
     * @param array<non-empty-string, non-empty-string> $tags
     * @param Tree<Edge>|null $tree Exact call tree, when the profiler records one.
     */
    public function __construct(
        public \DateTimeInterface $date = new \DateTimeImmutable(),
        public array $metadata = [],
        public array $tags = [],
        ?Graph $calls = null,
        ?Peaks $peaks = null,
        private ?Tree $tree = null,
    ) {
        $this->uuid = Uuid::uuid4();
        $this->calls = $calls ?? new Graph();
        if ($peaks === null) {
            $this->peaks = new Peaks();

            /** @var Edge $edge */
            foreach ($this->calls as $edge) {
                $this->peaks->update($edge->cost);
            }
        } else {
            $this->peaks = $peaks;
        }
    }

    /**
     * Builds a profile of a profiler that sums the calls by caller and callee pairs.
     *
     * @param Graph $calls Edges with raw costs; function IDs carry recursion levels (`foo@1`),
     *        so the graph has no cycles. Callers that are never called get synthetic roots.
     * @param Metadata $metadata
     * @param array<non-empty-string, non-empty-string> $tags
     */
    public static function fromGraph(
        Graph $calls,
        \DateTimeInterface $date = new \DateTimeImmutable(),
        array $metadata = [],
        array $tags = [],
    ): self {
        // Peaks of the recorded calls only: a synthetic root sums its children
        $peaks = self::peaksOf($calls);
        $calls->addMissingRoots();
        $calls->setPercentsAndDeltas($peaks);

        return new self($date, $metadata, $tags, $calls, $peaks);
    }

    /**
     * Builds a profile of a profiler that records the call tree.
     *
     * @param Tree<Edge> $tree Nodes with raw costs and function names; recursion levels are added here.
     * @param Metadata $metadata
     * @param array<non-empty-string, non-empty-string> $tags
     */
    public static function fromTree(
        Tree $tree,
        \DateTimeInterface $date = new \DateTimeImmutable(),
        array $metadata = [],
        array $tags = [],
    ): self {
        $tree = self::withRecursionLevels($tree);
        $calls = Graph::fromTree($tree);
        $peaks = self::peaksOf($calls);
        $calls->setPercentsAndDeltas($peaks);

        foreach ($tree->getIterator() as $branch) {
            $branch->item->cost->setPercentsOf($peaks);
            $branch->parent === null or $branch->item->cost->setDeltasTo($branch->parent->item->cost);
        }

        return new self($date, $metadata, $tags, $calls, $peaks, $tree);
    }

    /**
     * @param ProfileInput $data
     */
    public static function fromArray(array $data): self
    {
        $metadata = $data;
        unset($metadata['tags'], $metadata['peaks'], $metadata['total_edges'], $metadata['date'], $metadata['profile_uuid']);

        $profile = new self(
            date: new \DateTimeImmutable('@' . $data['date']),
            metadata: $metadata,
            tags: $data['tags'],
            // todo calls from edges
            peaks: Peaks::fromArray($data['peaks']),
        );

        /** @var mixed $uuid */
        $uuid = $data['profile_uuid'] ?? null;
        \is_string($uuid) && $uuid !== '' and $profile->uuid = $uuid;

        return $profile;
    }

    /**
     * Call tree: the exact one when the profiler records it, otherwise unfolded from the call graph.
     *
     * @return Tree<Edge>
     */
    public function tree(): Tree
    {
        return $this->tree ??= $this->calls->unfold(self::UNFOLD_MIN_SHARE);
    }

    /**
     * @return ProfileData
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'date' => $this->date->getTimestamp(),
            'app_name' => $this->metadata['app_name'] ?? '',
            'hostname' => (string) ($this->metadata['hostname'] ?? ''),
            'filename' => (string) ($this->metadata['filename'] ?? ''),
            'profile_uuid' => $this->uuid,
            'tags' => $this->tags,
            'peaks' => $this->peaks,
            'total_edges' => $this->calls->count(),
        ];
    }

    private static function peaksOf(Graph $calls): Peaks
    {
        $peaks = new Peaks();
        foreach ($calls as $edge) {
            $peaks->update($edge->cost);
        }

        return $peaks;
    }

    /**
     * Copies the tree, marking nested calls of a function with their level: `foo`, `foo@1`, `foo@2`.
     *
     * @param Tree<Edge> $tree
     *
     * @return Tree<Edge>
     */
    private static function withRecursionLevels(Tree $tree): Tree
    {
        /** @var Tree<Edge> $result */
        $result = new Tree();

        // Iterative: the depth follows the recursion in the profiled code, deeper than Xdebug's nesting limit
        /** @var list<array{Branch<Edge>, Branch<Edge>|null}> $stack Source node and the copy of its parent */
        $stack = \array_map(static fn(Branch $branch): array => [$branch, null], \array_reverse(\array_values($tree->root)));
        while ($stack !== []) {
            [$branch, $parent] = \array_pop($stack);
            $edge = $branch->item;

            $level = 0;
            for ($p = $parent; $p !== null; $p = $p->parent) {
                $p->item->callee === $edge->callee and ++$level;
            }

            $result->addItem(
                new Edge(
                    caller: $parent?->item->callee,
                    callee: $edge->callee,
                    cost: $edge->cost,
                    calleeId: $level === 0 ? $edge->callee : "{$edge->callee}@$level",
                    callerId: $parent?->item->calleeId,
                ),
                $branch->id,
                $parent?->id,
            );

            /** @var Branch<Edge> $copy */
            $copy = $result->all[$branch->id];
            foreach (\array_reverse($branch->children) as $child) {
                $stack[] = [$child, $copy];
            }
        }

        return $result;
    }
}
