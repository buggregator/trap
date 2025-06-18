<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Module\Profiler\Message\CallGraph;

/**
 * @internal
 */
final class Edge implements \JsonSerializable
{
    public function __construct(
        public readonly string $color,
        public readonly string $label,
        public readonly string $source,
        public readonly string $target,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'data' => [
                'color' => $this->color,
                'label' => $this->label,
                'source' => $this->source,
                'target' => $this->target,
            ],
        ];
    }
}
