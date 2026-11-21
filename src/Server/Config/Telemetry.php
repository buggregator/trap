<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Config;

use Buggregator\Trap\Server\Application\Config\Internal\Attribute\Env;
use Buggregator\Trap\Server\Application\Config\Internal\Attribute\InflectableConfig;
use Buggregator\Trap\Server\Application\Config\Internal\Attribute\XPath;

/**
 * Limits of the telemetry buffer: the traces, logs and metrics of the Sentry pages of the web UI.
 * Values below 1 fall back to the defaults.
 *
 * @internal
 */
#[InflectableConfig]
final class Telemetry
{
    /**
     * Spans and transactions kept. The least recently updated traces are dropped above it.
     */
    #[Env('TRAP_TELEMETRY_MAX_SPANS')]
    #[XPath('/trap/telemetry/@maxSpans')]
    public int $maxSpans = 10_000;

    /**
     * Log records kept. The oldest ones are dropped above it.
     */
    #[Env('TRAP_TELEMETRY_MAX_LOGS')]
    #[XPath('/trap/telemetry/@maxLogs')]
    public int $maxLogs = 5_000;

    /**
     * Metric values kept. The oldest ones are dropped above it.
     */
    #[Env('TRAP_TELEMETRY_MAX_METRICS')]
    #[XPath('/trap/telemetry/@maxMetrics')]
    public int $maxMetrics = 5_000;
}
