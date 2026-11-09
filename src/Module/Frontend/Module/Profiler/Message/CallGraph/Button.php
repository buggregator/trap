<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Module\Profiler\Message\CallGraph;

/**
 * @internal
 */
final class Button implements \JsonSerializable
{
    public function __construct(
        public readonly string $label,
        public readonly string $metric,
        public readonly string $description,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'label' => $this->label,
            'metric' => $this->metric,
            'description' => $this->description,
        ];
    }
}
