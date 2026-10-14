<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Console\Renderer\Otlp;

use Buggregator\Trap\Proto\Frame\Otlp\Trace\Span;

/**
 * The spans of one trace known so far: the stored ones and the ones of the current frame.
 *
 * @internal
 * @psalm-internal Buggregator\Trap\Sender\Console\Renderer
 */
final class KnownSpans
{
    /**
     * @param \Closure(string): ?Span $lookup Span by its ID.
     * @param \Closure(string): list<Span> $childrenOf Child spans of a span ID, in the order of arrival.
     * @param int $start Unix-nano start of the earliest span.
     * @param int $end Unix-nano end of the latest span.
     * @param int<0, max> $count
     */
    public function __construct(
        private readonly \Closure $lookup,
        private readonly \Closure $childrenOf,
        public readonly int $start,
        public readonly int $end,
        public readonly int $count,
    ) {}

    public function get(string $spanId): ?Span
    {
        return ($this->lookup)($spanId);
    }

    /**
     * @return list<Span>
     */
    public function children(string $spanId): array
    {
        return ($this->childrenOf)($spanId);
    }
}
