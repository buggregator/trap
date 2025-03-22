<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Module\Profiler\Message\TopFunctions;

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

    public function jsonSerialize(): array
    {
        return $this->columns;
    }
}
