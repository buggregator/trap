<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Struct;

/**
 * @internal
 */
final class Edge implements \JsonSerializable
{
    /**
     * @param non-empty-string|null $caller
     * @param non-empty-string $callee
     */
    public function __construct(
        public readonly ?string $caller,
        public readonly string $callee,
        public readonly Cost $cost,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'caller' => $this->caller,
            'callee' => $this->callee,
            'cost' => $this->cost,
        ];
    }
}
