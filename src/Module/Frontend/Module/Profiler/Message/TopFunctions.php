<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Module\Profiler\Message;

/**
 * @internal
 */
final class TopFunctions implements \JsonSerializable
{
    /**
     * @param list<TopFunctions\Func> $functions
     */
    public function __construct(
        private readonly array $functions,
        private readonly \Buggregator\Trap\Module\Profiler\Struct\Peaks $overallTotals,
        private readonly TopFunctions\Schema $schema,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'functions' => $this->functions,
            'overall_totals' => $this->overallTotals,
            'schema' => $this->schema,
        ];
    }
}
