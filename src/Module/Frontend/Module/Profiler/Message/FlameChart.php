<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Module\Profiler\Message;

/**
 * @internal
 */
final class FlameChart implements \JsonSerializable
{
    public function __construct(
    ) {}

    public function jsonSerialize(): array
    {
        return [];
    }
}
