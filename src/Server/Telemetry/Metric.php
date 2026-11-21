<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Telemetry;

/**
 * One value of a metric: a Sentry trace metric, or an OTLP data point.
 *
 * @internal
 */
final class Metric
{
    /**
     * @param non-empty-string $id
     * @param non-empty-string $project
     * @param non-empty-string $name
     * @param string $type `counter`, `gauge` or `distribution`.
     * @param float|null $time Unix seconds of the value.
     * @param array<array-key, mixed> $attributes As sent: Sentry ones are typed, `{"type": ..., "value": ...}`.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $project,
        public readonly string $name,
        public readonly string $type,
        public readonly float $value,
        public readonly ?float $time,
        public readonly Source $source,
        public readonly string $unit = '',
        public readonly ?string $traceId = null,
        public readonly ?string $spanId = null,
        public readonly ?string $service = null,
        public readonly array $attributes = [],
    ) {}
}
