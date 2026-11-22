<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Sentry\Frontend;

use Buggregator\Trap\Module\Sentry\Frame\SentryEnvelope as SentryFrame;
use Buggregator\Trap\Module\Sentry\Telemetry\ItemReader;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\EventMapper;
use Buggregator\Trap\Server\Proto\Frame;
use Buggregator\Trap\Server\Telemetry\Batch;
use Buggregator\Trap\Server\Telemetry\TelemetryStore;
use Buggregator\Trap\Support\Uuid;

/**
 * Makes the feed event of an envelope from its event item, and puts its transactions, spans, logs and
 * trace metrics into the {@see TelemetryStore}: as in buggregator/server, they make no feed event.
 *
 * The feed event is the first `event` item, else the first `feedback` one, else the first transaction without
 * a trace ID, which no trace can hold, else the first item that is neither telemetry nor SDK bookkeeping
 * (sessions, client reports, attachments, ...). An envelope of only such items makes no event.
 *
 * @internal
 */
final class EnvelopeMapper implements EventMapper
{
    private const BOOKKEEPING_ITEMS = [
        'session', 'sessions', 'client_report', 'check_in', 'metric_buckets', 'statsd',
        'profile', 'profile_chunk', 'user_report', 'attachment', 'replay_event', 'replay_recording',
    ];

    public function __construct(
        private readonly TelemetryStore $telemetry = new TelemetryStore(),
    ) {}

    #[\Override]
    public function map(Frame $frame): ?Event
    {
        \assert($frame instanceof SentryFrame);

        /** @var mixed $eventId */
        $eventId = $frame->headers['event_id'] ?? null;
        $eventId = \is_string($eventId) && $eventId !== '' ? $eventId : null;
        $project = $frame->project ?? Event::DEFAULT_PROJECT;
        $project === '' and $project = Event::DEFAULT_PROJECT;

        $batch = new Batch($project);
        $event = null;
        $feedback = null;
        $untraced = null;
        $fallback = null;
        foreach ($frame->items as $item) {
            /** @var mixed $type */
            $type = $item->headers['type'] ?? null;
            $type = \is_string($type) ? $type : '';
            $telemetry = ItemReader::read($batch, $type, $item->payload, $eventId);

            if ($telemetry || !\is_array($item->payload)) {
                continue;
            }

            if ($type === 'event') {
                $event ??= $item->payload;
            } elseif ($type === 'feedback') {
                $feedback ??= $item->payload;
            } elseif ($type === 'transaction') {
                $untraced ??= $item->payload;
            } elseif (!\in_array($type, self::BOOKKEEPING_ITEMS, true)) {
                $fallback ??= $item->payload;
            }
        }

        $this->telemetry->add($batch);

        $payload = $event ?? $feedback ?? $untraced ?? $fallback;
        if ($payload === null) {
            return null;
        }

        // SDK 4+ puts `event_id` into the envelope header only.
        $eventId === null || isset($payload['event_id']) or $payload['event_id'] = $eventId;

        return new Event(
            uuid: Uuid::uuid4(),
            type: 'sentry',
            payload: $payload,
            timestamp: (float) $frame->time->format('U.u'),
            project: $frame->project,
        );
    }
}
