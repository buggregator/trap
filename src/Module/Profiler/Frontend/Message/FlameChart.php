<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Frontend\Message;

/**
 * @internal
 */
final class FlameChart implements \JsonSerializable
{
    /**
     * @param list<FlameChart\Span> $roots
     */
    public function __construct(
        private readonly array $roots,
    ) {}

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->roots;
    }
}
