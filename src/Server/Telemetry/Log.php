<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Telemetry;

/**
 * A log record of a Sentry SDK or of OTLP.
 *
 * @internal
 */
final class Log
{
    /**
     * @param non-empty-string $id
     * @param non-empty-string $project
     * @param float|null $time Unix seconds when the record was made.
     * @param array<array-key, mixed> $attributes As sent: Sentry ones are typed, `{"type": ..., "value": ...}`.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $project,
        public readonly string $level,
        public readonly string $body,
        public readonly ?float $time,
        public readonly Source $source,
        public readonly ?string $traceId = null,
        public readonly ?string $spanId = null,
        public readonly ?int $severityNumber = null,
        public readonly ?string $service = null,
        public readonly array $attributes = [],
    ) {}
}
