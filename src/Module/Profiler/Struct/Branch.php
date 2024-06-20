<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Struct;

/**
 * @template-covariant T of object
 *
 * @internal
 */
final class Branch
{
    /**
     * @param T $item
     * @param non-empty-string $id
     * @param non-empty-string|null $parentId
     * @param list<Branch<T>> $children
     * @param Branch<T>|null $parent
     */
    public function __construct(
        public object $item,
        public readonly string $id,
        public readonly ?string $parentId,
        public array $children = [],
        public ?Branch $parent = null,
    ) {}

    public function __destruct()
    {
        unset($this->item, $this->children, $this->parent);
    }
}
