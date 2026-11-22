<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Tracing\Config;

use Buggregator\Trap\Module\Tracing\Console\SpanBuffer;
use Buggregator\Trap\Server\Application\Config\Internal\Attribute\Env;
use Buggregator\Trap\Server\Application\Config\Internal\Attribute\InflectableConfig;
use Buggregator\Trap\Server\Application\Config\Internal\Attribute\XPath;

/**
 * Limits of the {@see SpanBuffer} of the OpenTelemetry spans the console prints,
 * which joins spans of one trace exported in separate requests.
 *
 * @internal
 */
#[InflectableConfig]
final class Traces
{
    /**
     * The maximum number of stored spans. Least recently updated traces are dropped above it.
     * Values below 1 fall back to the default.
     */
    #[Env('TRAP_TRACES_MAX_SPANS')]
    #[XPath('/trap/traces/@maxSpans')]
    public int $maxSpans = 10_000;

    /**
     * Seconds a trace is kept after its last received span.
     * Values not above 0 fall back to the default.
     */
    #[Env('TRAP_TRACES_TTL')]
    #[XPath('/trap/traces/@ttl')]
    public float $ttl = 600.0;
}
