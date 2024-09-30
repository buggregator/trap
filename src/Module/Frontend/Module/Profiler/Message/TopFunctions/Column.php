<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Module\Profiler\Message\TopFunctions;

/**
 * @internal
 */
final class Column implements \JsonSerializable
{
    /**
     * @param list<Value> $values
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly bool $sortable,
        public readonly string $description,
        public readonly array $values,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'sortable' => $this->sortable,
            'description' => $this->description,
            'values' => $this->values,
        ];
    }
}
