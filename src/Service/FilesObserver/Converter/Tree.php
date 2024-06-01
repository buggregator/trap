<?php

declare(strict_types=1);

namespace Buggregator\Trap\Service\FilesObserver\Converter;

/**
 * @template-covariant TItem of object
 *
 * @implements \IteratorAggregate<Branch<TItem>>
 *
 * @internal
 */
final class Tree implements \IteratorAggregate
{
    /** @var array<non-empty-string, Branch<TItem>> */
    private array $root = [];

    /** @var array<non-empty-string, Branch<TItem>> */
    private array $all = [];

    /** @var array<non-empty-string, Branch<TItem>> */
    private array $lostChildren = [];

    /**
     * @template-covariant T of object
     *
     * @param list<T> $edges
     * @param callable(T): non-empty-string $getCurrent Get current node id
     * @param callable(T): (non-empty-string|null) $getParent Get parent node id
     *
     * @return self<T>
     */
    public static function fromEdgesList(array $edges, callable $getCurrent, callable $getParent): self
    {
        $tree = new self();

        foreach ($edges as $edge) {
            $current = $getCurrent($edge);
            $parent = $getParent($edge);

            $tree->addItem($edge, $current, $parent);
        }

        return $tree;
    }

    /**
     * @param TItem $item
     * @param non-empty-string $id
     * @param non-empty-string|null $parentId
     */
    public function addItem(object $item, string $id, ?string $parentId): void
    {
        $branch = new Branch($item, $id, $parentId);
        $this->all[$id] = $branch;

        if ($parentId === null) {
            $this->root[$id] = $branch;
        } else {
            $branch->parent = $this->all[$parentId] ?? null;

            $branch->parent === null
                ? $this->lostChildren[$id] = $branch
                : $branch->parent->children[] = $branch;
        }

        foreach ($this->lostChildren as $lostChild) {
            if ($lostChild->parentId === $id) {
                $branch->children[] = $lostChild;
                unset($this->lostChildren[$lostChild->id]);
            }
        }
    }

    /**
     * Iterate all the branches without sorting and hierarchy.
     *
     * @return \Traversable<non-empty-string, Branch<TItem>>
     */
    public function getIterator(): \Traversable
    {
        yield from $this->all;
    }

    /**
     * Yield items by the level in the hierarchy with custom sorting in level scope
     *
     * @param callable(Branch<TItem>, Branch<TItem>): int $sorter
     *
     * @return \Traversable<TItem>
     */
    public function getItemsSortedV1(?callable $sorter): \Traversable
    {
        $level = 0;
        $queue = [$level => $this->root];
        processLevel:
        while ($queue[$level] !== []) {
            $branch = \array_shift($queue[$level]);
            yield $branch->item;

            // Fill the next level
            $queue[$level + 1] ??= [];
            \array_unshift($queue[$level + 1], ...$branch->children);
        }

        if (\array_key_exists(++$level, $queue)) {
            $sorter === null or \usort($queue[$level], $sorter);

            goto processLevel;
        }
    }


    /**
     * Yield items deep-first.
     *
     * @param callable(Branch<TItem>, Branch<TItem>): int $sorter
     *
     * @return \Traversable<TItem>
     */
    public function getItemsSortedV0(?callable $sorter): \Traversable
    {
        $queue = $this->root;
        while (\count($queue) > 0) {
            $branch = \array_shift($queue);
            yield $branch->item;

            $children = $branch->children;
            $sorter === null or \usort($children, $sorter);

            \array_unshift($queue, ...$children);
        }
    }

    public function __destruct()
    {
        foreach ($this->all as $branch) {
            $branch->__destruct();
        }

        unset($this->all, $this->root, $this->lostChildren);
    }
}
