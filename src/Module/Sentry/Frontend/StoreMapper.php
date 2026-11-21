<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Sentry\Frontend;

use Buggregator\Trap\Module\Sentry\Frame\SentryStore as SentryFrame;
use Buggregator\Trap\Module\Sentry\Telemetry\ItemReader;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\EventMapper;
use Buggregator\Trap\Server\Proto\Frame;
use Buggregator\Trap\Server\Telemetry\Batch;
use Buggregator\Trap\Server\Telemetry\TelemetryStore;
use Buggregator\Trap\Support\Uuid;

/**
 * A transaction sent to the `store` endpoint goes to the {@see TelemetryStore} as well.
 *
 * @internal
 */
final class StoreMapper implements EventMapper
{
    public function __construct(
        private readonly TelemetryStore $telemetry = new TelemetryStore(),
    ) {}

    #[\Override]
    public function map(Frame $frame): Event
    {
        \assert($frame instanceof SentryFrame);

        $project = $frame->project === null || $frame->project === '' ? Event::DEFAULT_PROJECT : $frame->project;
        if (ItemReader::isTransaction($frame->message)) {
            $batch = new Batch($project);
            ItemReader::transaction($batch, $frame->message);
            $this->telemetry->add($batch);
        }

        return new Event(
            uuid: Uuid::uuid4(),
            type: 'sentry',
            payload: $frame->message,
            timestamp: (float) $frame->time->format('U.u'),
            project: $frame->project,
        );
    }
}
