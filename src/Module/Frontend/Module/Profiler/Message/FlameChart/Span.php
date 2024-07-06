<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Module\Profiler\Message\FlameChart;

use Buggregator\Trap\Module\Profiler\Struct\Cost;

/**
 * @internal
 */
final class Span implements \JsonSerializable
{
    /**
     * @param float $duration (ms)
     */
    public function __construct(
        public readonly string $name,
        public readonly float $start,
        public readonly float $duration,
        public readonly Cost $cost,
        public readonly string $type = 'task',
        public readonly string $color = '#aaaaaa',
        public array $children = [],
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'start' => $this->start,
            'duration' => $this->duration,
            'cost' => $this->cost,
            'type' => $this->type,
            'color' => $this->color,
            'children' => $this->children,
        ];
    }
}
