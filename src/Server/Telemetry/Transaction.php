<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Telemetry;

/**
 * The span where a request entered a service: a Sentry transaction, or the entry span (segment)
 * of a service in a Sentry span stream or an OTLP export. Times are Unix seconds.
 *
 * @internal
 */
final class Transaction
{
    /**
     * @param non-empty-string $id Unique in its trace: a transaction with a stored ID replaces it.
     * @param non-empty-string $project
     * @param non-empty-string $traceId
     * @param array<array-key, mixed> $attributes Sentry `contexts.trace.data`, or OTel attributes with `otel.*` extras.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $project,
        public readonly string $traceId,
        public readonly ?string $spanId,
        public readonly ?string $parentSpanId,
        public readonly string $name,
        public readonly string $op,
        public readonly string $status,
        public readonly ?float $start,
        public readonly ?float $end,
        public readonly Source $source,
        public readonly ?string $service,
        public readonly array $attributes = [],
        public readonly ?string $environment = null,
        public readonly ?string $release = null,
    ) {}

    /**
     * @return int<0, max>|null
     */
    public function durationMs(): ?int
    {
        return Span::durationBetween($this->start, $this->end);
    }

    public function isError(): bool
    {
        return Span::isErrorStatus($this->status);
    }
}
