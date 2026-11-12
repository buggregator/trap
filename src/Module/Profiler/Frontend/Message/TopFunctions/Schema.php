<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Frontend\Message\TopFunctions;

/**
 * @internal
 */
final class Schema implements \JsonSerializable
{
    /**
     * @param list<Column> $columns
     */
    public function __construct(
        private array $columns,
    ) {}

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->columns;
    }
}
