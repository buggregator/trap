<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Sentry\Frontend;

use Buggregator\Trap\Module\Sentry\Frame\EnvelopeItem;
use Buggregator\Trap\Module\Sentry\Frame\SentryEnvelope;
use Buggregator\Trap\Module\Sentry\Frame\SentryStore;
use Buggregator\Trap\Module\Sentry\Frontend\EnvelopeMapper;
use Buggregator\Trap\Module\Sentry\Frontend\StoreMapper;
use Buggregator\Trap\Server\Telemetry\TelemetryStore;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(EnvelopeMapper::class)]
#[Covers(StoreMapper::class)]
#[Test]
final class EnvelopeMapperTest
{
    private const TRACE = '5b8efff798038103d269b633813fc60c';

    public function testEventItemMakesTheFeedEventAndTelemetryGoesToTheStore(): void
    {
        $store = new TelemetryStore();
        $frame = new SentryEnvelope(['event_id' => 'e1'], [
            new EnvelopeItem(['type' => 'log'], ['items' => [['body' => 'hi', 'level' => 'info']]]),
            new EnvelopeItem(['type' => 'event'], ['level' => 'error', 'message' => 'Boom']),
            new EnvelopeItem(['type' => 'trace_metric'], ['items' => [['name' => 'm', 'value' => 1]]]),
        ], project: 'shop');

        $event = (new EnvelopeMapper($store))->map($frame);

        Assert::notBlank($event);
        Assert::same($event->type, 'sentry');
        Assert::same($event->projectKey(), 'shop');
        Assert::same($event->payload, ['level' => 'error', 'message' => 'Boom', 'event_id' => 'e1']);
        Assert::count($store->logs(), 1);
        Assert::same($store->logs()[0]->project, 'shop');
        Assert::count($store->metrics(), 1);
    }

    public function testTransactionBecomesATraceWithoutAFeedEvent(): void
    {
        $store = new TelemetryStore();
        $transaction = ['event_id' => 't1', 'contexts' => ['trace' => ['trace_id' => self::TRACE]], 'spans' => []];
        $frame = new SentryEnvelope([], [new EnvelopeItem(['type' => 'transaction'], $transaction)]);

        $event = (new EnvelopeMapper($store))->map($frame);

        Assert::null($event);
        Assert::same(\array_keys($store->trace(self::TRACE)?->transactions ?? []), ['t1']);
        Assert::same($store->trace(self::TRACE)?->transactions['t1']->project, 'default');
    }

    public function testSpanOnlyEnvelopeMakesNoEvent(): void
    {
        $store = new TelemetryStore();
        $frame = new SentryEnvelope([], [new EnvelopeItem(['type' => 'span'], [
            'trace_id' => self::TRACE,
            'span_id' => 'aaaaaaaaaaaaaaaa',
            'name' => 'GET /',
            'is_segment' => true,
            'start_timestamp' => 1,
            'end_timestamp' => 2,
        ])]);

        Assert::null((new EnvelopeMapper($store))->map($frame));
        Assert::notBlank($store->trace(self::TRACE));
    }

    public function testErrorWithTransactionMakesTheErrorEventAndATrace(): void
    {
        $store = new TelemetryStore();
        $frame = new SentryEnvelope([], [
            new EnvelopeItem(['type' => 'transaction'], ['contexts' => ['trace' => ['trace_id' => self::TRACE]], 'spans' => []]),
            new EnvelopeItem(['type' => 'event'], ['message' => 'Boom']),
        ]);

        $event = (new EnvelopeMapper($store))->map($frame);

        Assert::same($event?->payload, ['message' => 'Boom']);
        Assert::count($store->trace(self::TRACE)?->transactions ?? [], 1);
    }

    public function testFeedbackMakesTheFeedEvent(): void
    {
        $frame = new SentryEnvelope([], [
            new EnvelopeItem(['type' => 'session'], ['sid' => 'x']),
            new EnvelopeItem(['type' => 'feedback'], ['contexts' => ['feedback' => ['message' => 'Nice']]]),
        ]);

        $event = (new EnvelopeMapper(new TelemetryStore()))->map($frame);

        Assert::same($event?->payload, ['contexts' => ['feedback' => ['message' => 'Nice']]]);
    }

    public function testTelemetryOnlyEnvelopeMakesNoEvent(): void
    {
        $store = new TelemetryStore();
        $frame = new SentryEnvelope([], [
            new EnvelopeItem(['type' => 'log'], ['items' => [['body' => 'hi']]]),
            new EnvelopeItem(['type' => 'client_report'], ['discarded_events' => []]),
            new EnvelopeItem(['type' => 'attachment'], 'binary'),
        ]);

        Assert::null((new EnvelopeMapper($store))->map($frame));
        Assert::count($store->logs(), 1);
    }

    public function testUnknownItemTypeStillMakesAnEvent(): void
    {
        $frame = new SentryEnvelope([], [
            new EnvelopeItem(['type' => 'session'], ['sid' => 'x']),
            new EnvelopeItem(['type' => 'something_new'], ['a' => 1]),
        ]);

        $event = (new EnvelopeMapper(new TelemetryStore()))->map($frame);

        Assert::same($event?->payload, ['a' => 1]);
    }

    public function testStoreEndpointTransactionGoesToTheStoreOnly(): void
    {
        $store = new TelemetryStore();
        $payload = [
            'event_id' => 'x1',
            'start_timestamp' => 1,
            'spans' => [],
            'contexts' => ['trace' => ['trace_id' => self::TRACE]],
        ];

        $event = (new StoreMapper($store))->map(new SentryStore($payload, project: 'shop'));

        Assert::null($event);
        Assert::same($store->trace(self::TRACE)?->transactions['x1']->project, 'shop');
    }

    public function testStoreEndpointErrorMakesAnEventAndStaysOutOfTheStore(): void
    {
        $store = new TelemetryStore();

        $event = (new StoreMapper($store))->map(new SentryStore(['event_id' => 'x1', 'message' => 'Boom'], project: 'shop'));

        Assert::same($event?->projectKey(), 'shop');
        Assert::same($store->traces(), []);
    }
}
