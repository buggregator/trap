<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Frontend\Message;

/**
 * @internal
 */
final class CallGraph implements \JsonSerializable
{
    /** A node per recursion level of a function. */
    public const RECURSION_LEVELS = 'levels';

    /** A node per function, calling itself in direct recursion. */
    public const RECURSION_COLLAPSED = 'collapsed';

    /**
     * @param list<CallGraph\Node> $nodes
     * @param list<CallGraph\Edge> $edges
     * @param self::RECURSION_* $recursion How the graph shows recursion; the UI tells by it that the server takes
     *        the `recursion` option.
     */
    public function __construct(
        private readonly CallGraph\Toolbar $toolbar,
        private readonly array $nodes,
        private readonly array $edges,
        private readonly string $recursion = self::RECURSION_LEVELS,
    ) {}

    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'toolbar' => $this->toolbar,
            'nodes' => $this->nodes,
            'edges' => $this->edges,
            'recursion' => $this->recursion,
        ];
    }
}
