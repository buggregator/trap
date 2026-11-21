<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Telemetry;

/**
 * A span inside a service: a child span of a Sentry transaction, a Sentry v2 span, or an OTLP span
 * that is not the entry span of its service. Times are Unix seconds.
 *
 * @internal
 */
final class Span
{
    /**
     * @param non-empty-string $project
     * @param non-empty-string $traceId
     * @param non-empty-string $spanId
     * @param string|null $transactionId The {@see Transaction::$id} of the Sentry transaction it came in.
     * @param array<array-key, mixed> $attributes Sentry span data, or OTel attributes with `otel.*` extras.
     */
    public function __construct(
        public readonly string $project,
        public readonly string $traceId,
        public readonly string $spanId,
        public readonly ?string $parentSpanId,
        public readonly ?string $transactionId,
        public readonly string $op,
        public readonly string $description,
        public readonly string $status,
        public readonly ?float $start,
        public readonly ?float $end,
        public readonly Source $source,
        public readonly ?string $service,
        public readonly Peer $peer = new Peer(),
        public readonly array $attributes = [],
    ) {}

    /**
     * The failure statuses of Sentry spans: `internal_error`, `unknown_error`, and `error` of v2 spans.
     */
    public static function isErrorStatus(string $status): bool
    {
        return \in_array($status, ['internal_error', 'unknown_error', 'error'], true);
    }

    /**
     * @return int<0, max>|null
     */
    public static function durationBetween(?float $start, ?float $end): ?int
    {
        return $start === null || $end === null ? null : \max(0, (int) \round(($end - $start) * 1000.0));
    }

    /**
     * @return int<0, max>|null
     */
    public function durationMs(): ?int
    {
        return self::durationBetween($this->start, $this->end);
    }

    public function isError(): bool
    {
        return self::isErrorStatus($this->status);
    }
}
