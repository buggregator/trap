<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Frontend\Message\CallGraph;

/**
 * @internal
 */
final class Metrics implements \JsonSerializable
{
    public function __construct(
        private readonly int $cost,
        private readonly float $percents,
    ) {}

    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'cost' => $this->cost,
            'percents' => $this->percents,
        ];
    }
}
