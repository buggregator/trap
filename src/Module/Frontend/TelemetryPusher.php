<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend;

use Buggregator\Trap\Server\Telemetry\TelemetryListener;

/**
 * Tells the UI clients of the project what the telemetry store got, with the `sentry.stored` push
 * `{"project": ..., "kinds": ["trace", "log", "metric"]}`: telemetry makes no feed events,
 * so the Sentry pages would not know to fetch their lists again.
 *
 * @internal
 */
final class TelemetryPusher implements TelemetryListener
{
    public function __construct(
        private readonly Publisher $publisher,
    ) {}

    #[\Override]
    public function stored(string $project, array $kinds): void
    {
        $this->publisher->toProject($project, 'sentry.stored', ['project' => $project, 'kinds' => $kinds]);
    }
}
