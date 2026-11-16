<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Tracing;

/**
 * A stored trace at a glance, see {@see SpanStore::traces()}.
 *
 * @internal
 */
final class TraceSummary
{
    /**
     * @param non-empty-string $traceId
     * @param string|null $name Name of the root span: the one without a parent, or else the earliest one whose
     *        parent has not arrived; null when every span has a stored parent, as in a parent cycle.
     * @param string|null $service `service.name` of the resource of the root span, or else of any span.
     * @param int<1, max> $spans
     * @param int $start Unix nanoseconds of the earliest span start.
     * @param int $end Unix nanoseconds of the latest span end.
     * @param bool $error Whether a span has the error status.
     * @param float $updatedAt Unix time of the last received span, in seconds.
     * @param int<1, max> $seq The store sequence number of the last stored span of the trace.
     */
    public function __construct(
        public readonly string $traceId,
        public readonly ?string $name,
        public readonly ?string $service,
        public readonly int $spans,
        public readonly int $start,
        public readonly int $end,
        public readonly bool $error,
        public readonly float $updatedAt,
        public readonly int $seq,
    ) {}
}
