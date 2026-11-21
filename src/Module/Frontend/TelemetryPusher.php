<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend;

use Buggregator\Trap\Module\Frontend\Message\Push;
use Buggregator\Trap\Server\Telemetry\TelemetryListener;
use Buggregator\Trap\Server\Traffic\Websocket\Frame;
use Buggregator\Trap\Support\Json;

/**
 * Tells the connected UI clients what the telemetry store got, with the `sentry.stored` push
 * `{"project": ..., "kinds": ["trace", "log", "metric"]}`: telemetry makes no feed events,
 * so the Sentry pages would not know to fetch their lists again.
 *
 * @internal
 */
final class TelemetryPusher implements TelemetryListener
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    #[\Override]
    public function stored(string $project, array $kinds): void
    {
        $this->connectionPool->send(Frame::text(Json::encode(
            new Push(
                event: 'sentry.stored',
                channel: "events.project.$project",
                data: ['project' => $project, 'kinds' => $kinds],
            ),
        )));
    }
}
