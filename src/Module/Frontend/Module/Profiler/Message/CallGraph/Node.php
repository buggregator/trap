<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Module\Profiler\Message\CallGraph;

use Buggregator\Trap\Module\Profiler\Struct\Cost;

/**
 * @internal
 */
final class Node implements \JsonSerializable
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly Cost $cost,
        public readonly Metrics $metrics,
        public readonly string $color,
        public readonly string $textColor,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'data' => [
                'color' => $this->color,
                'cost' => $this->cost,
                'id' => $this->id,
                'metrics' => $this->metrics,
                'name' => $this->name,
                'textColor' => $this->textColor,
            ],
        ];
    }
}
