<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Module\Profiler\Message\CallGraph;

/**
 * @internal
 */
final class Metrics implements \JsonSerializable
{
    public function __construct(
        private readonly int $cost,
        private readonly float $percents,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'cost' => $this->cost,
            'percents' => $this->percents,
        ];
    }
}
