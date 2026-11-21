<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Telemetry;

/**
 * What sent a telemetry record: a Sentry SDK or an OpenTelemetry exporter over OTLP.
 *
 * @internal
 */
enum Source: string
{
    case Sentry = 'sentry';
    case Otel = 'otel';
}
