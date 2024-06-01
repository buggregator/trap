<?php

declare(strict_types=1);

namespace Buggregator\Trap\Service\FilesObserver\Converter;

/**
 * @internal
 */
final class Edge implements \JsonSerializable
{
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
