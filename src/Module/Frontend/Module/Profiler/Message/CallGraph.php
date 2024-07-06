<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Module\Profiler\Message;

/**
 * @internal
 */
final class CallGraph implements \JsonSerializable
{
    /**
     * @param list<CallGraph\Node> $nodes
     * @param list<CallGraph\Edge> $edges
     */
    public function __construct(
        private readonly CallGraph\Toolbar $toolbar,
        private readonly array $nodes,
        private readonly array $edges,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'toolbar' => $this->toolbar,
            'nodes' => $this->nodes,
            'edges' => $this->edges,
        ];
    }
}
