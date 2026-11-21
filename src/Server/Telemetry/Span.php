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
    /** A year */
    public const MAX_DURATION_MS = 31_536_000_000;

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
     * @return int<0, max>|null Milliseconds, at most a year: sums of durations stay ints, whatever times a
     *         client sends.
     */
    public static function durationBetween(?float $start, ?float $end): ?int
    {
        if ($start === null || $end === null) {
            return null;
        }

        $ms = ($end - $start) * 1000.0;
        if ($ms >= (float) self::MAX_DURATION_MS) {
            return self::MAX_DURATION_MS;
        }

        return $ms > 0.0 ? \max(0, (int) \round($ms)) : 0;
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
