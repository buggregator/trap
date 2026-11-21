<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Telemetry;

/**
 * Gets told what the {@see TelemetryStore} has just stored.
 *
 * @internal
 */
interface TelemetryListener
{
    /**
     * @param non-empty-string $project
     * @param non-empty-list<'trace'|'log'|'metric'> $kinds
     */
    public function stored(string $project, array $kinds): void;
}
