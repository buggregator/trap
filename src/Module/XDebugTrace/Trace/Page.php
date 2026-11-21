<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebugTrace\Trace;

/**
 * A slice of calls and where the next one starts.
 *
 * @internal
 */
final class Page implements \JsonSerializable
{
    public ?Node $parent = null;

    /** @var list<Node> */
    public array $nodes = [];

    /** Offset to pass as `from` for the next page; null when there is no more */
    public ?int $next = null;

    /** The trace was cut (no TRACE END) or hit the size limit inside the scan */
    public bool $truncated = false;

    #[\Override]
    public function jsonSerialize(): array
    {
        return ($this->parent === null ? [] : ['parent' => $this->parent]) + [
            'nodes' => $this->nodes,
            'next' => $this->next,
            'truncated' => $this->truncated,
        ];
    }
}
