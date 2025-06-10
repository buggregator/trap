<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Module\Profiler\Message\CallGraph;

/**
 * @internal
 */
final class Toolbar implements \JsonSerializable
{
    /**
     * @param list<Button> $buttons
     */
    public function __construct(
        private array $buttons,
    ) {}

    public function jsonSerialize(): array
    {
        return $this->buttons;
    }
}
