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

    public function testEventHasFacetsOfItsFields(): void
    {
        $frame = new SentryEnvelope([], [new EnvelopeItem(['type' => 'event'], [
            'server_name' => 'web-1',
            'environment' => 'staging',
            'level' => 'ERROR',
            'logger' => 'php',
            'release' => 'shop@1.0',
            'user' => ['id' => 7, 'email' => 'a@example.com'],
            'tags' => ['feature' => 'checkout'],
        ])]);

        Assert::same((new EnvelopeMapper(new TelemetryStore()))->map($frame)?->facets, [
            'host' => ['web-1'],
            'env' => ['staging'],
            'level' => ['error'],
            'channel' => ['php'],
            'release' => ['shop@1.0'],
            'user' => ['a@example.com'],
            'tag:feature' => ['checkout'],
        ]);
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

    public function testTransactionWithoutTraceIdStaysInTheFeed(): void
    {
        $store = new TelemetryStore();
        $transaction = ['event_id' => 't1', 'transaction' => 'GET /', 'spans' => []];
        $frame = new SentryEnvelope([], [
            new EnvelopeItem(['type' => 'client_report'], ['discarded_events' => []]),
            new EnvelopeItem(['type' => 'transaction'], $transaction),
        ]);

        $event = (new EnvelopeMapper($store))->map($frame);

        Assert::same($event?->payload, $transaction);
        Assert::same($store->traces(), []);
    }

    public function testEventWinsTheFeedOverATransactionWithoutTraceId(): void
    {
        $store = new TelemetryStore();
        $frame = new SentryEnvelope([], [
            new EnvelopeItem(['type' => 'transaction'], ['transaction' => 'GET /', 'spans' => []]),
            new EnvelopeItem(['type' => 'event'], ['message' => 'Boom']),
        ]);

        $event = (new EnvelopeMapper($store))->map($frame);

        Assert::same($event?->payload, ['message' => 'Boom']);
        Assert::same($store->traces(), []);
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

    public function testFeedbackGoesToTheStoreWithoutAFeedEvent(): void
    {
        $store = new TelemetryStore();
        $frame = new SentryEnvelope(['event_id' => 'fb1'], [
            new EnvelopeItem(['type' => 'session'], ['sid' => 'x']),
            new EnvelopeItem(['type' => 'feedback'], [
                'contexts' => ['feedback' => [
                    'message' => 'Nice',
                    'name' => 'Ann',
                    'url' => 'https://shop.test/cart',
                    'associated_event_id' => 'e1e1',
                ]],
                'user' => ['email' => 'ann@example.com'],
            ]),
        ], project: 'shop', time: new \DateTimeImmutable('@1767323045.5'));

        Assert::null((new EnvelopeMapper($store))->map($frame));

        [$feedback] = $store->feedback();
        Assert::same(
            [$feedback->project, $feedback->eventId, $feedback->feedbackId, $feedback->name, $feedback->email],
            ['shop', 'e1e1', 'fb1', 'Ann', 'ann@example.com'],
        );
        Assert::same([$feedback->message, $feedback->url, $feedback->source], ['Nice', 'https://shop.test/cart', 'feedback']);
        Assert::same($feedback->time, 1767323045.5);
    }

    public function testMalformedFeedbackIsDropped(): void
    {
        $store = new TelemetryStore();
        $frame = new SentryEnvelope([], [
            new EnvelopeItem(['type' => 'feedback'], 'not an object'),
            new EnvelopeItem(['type' => 'feedback'], ['contexts' => ['feedback' => 'text']]),
            new EnvelopeItem(['type' => 'feedback'], ['contexts' => ['feedback' => ['message' => ['x']]]]),
            new EnvelopeItem(['type' => 'feedback'], ['contexts' => 'x']),
            new EnvelopeItem(['type' => 'feedback'], ['user' => ['email' => 42]]),
            new EnvelopeItem(['type' => 'user_report'], ['comments' => 7]),
            new EnvelopeItem(['type' => 'feedback'], ['contexts' => ['feedback' => ['message' => 'Fine', 'url' => null]]]),
        ]);

        Assert::null((new EnvelopeMapper($store))->map($frame));
        Assert::same(\array_map(static fn($f): string => $f->message, $store->feedback()), ['Fine']);
    }

    public function testUserReportIsFeedbackOnItsError(): void
    {
        $store = new TelemetryStore();
        $frame = new SentryEnvelope([], [
            new EnvelopeItem(['type' => 'event'], ['event_id' => 'e2e2', 'message' => 'Boom']),
            new EnvelopeItem(['type' => 'user_report'], ['event_id' => 'e2e2', 'name' => 'Bob', 'email' => 'b@x.test', 'comments' => 'Again']),
        ]);

        $event = (new EnvelopeMapper($store))->map($frame);

        Assert::same($event?->payload['message'] ?? null, 'Boom');
        [$feedback] = $store->feedback();
        Assert::same(
            [$feedback->eventId, $feedback->feedbackId, $feedback->name, $feedback->email, $feedback->message, $feedback->source],
            ['e2e2', '', 'Bob', 'b@x.test', 'Again', 'user_report'],
        );
        Assert::true($feedback->isAbout('E2-E2'));
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

    public function testStoreEndpointTransactionWithoutTraceIdStaysInTheFeed(): void
    {
        $store = new TelemetryStore();

        $event = (new StoreMapper($store))->map(new SentryStore(['type' => 'transaction', 'transaction' => 'GET /']));

        Assert::same($event?->payload, ['type' => 'transaction', 'transaction' => 'GET /']);
        Assert::same($store->traces(), []);
    }

    public function testStoreEndpointTransactionWithExceptionIsAnError(): void
    {
        $store = new TelemetryStore();
        $payload = [
            'type' => 'transaction',
            'exception' => ['values' => [['type' => 'RuntimeException', 'value' => 'Boom']]],
            'contexts' => ['trace' => ['trace_id' => self::TRACE]],
        ];

        $event = (new StoreMapper($store))->map(new SentryStore($payload));

        Assert::same($event?->payload, $payload);
        Assert::same($store->traces(), []);
    }

    public function testStoreEndpointErrorMakesAnEventAndStaysOutOfTheStore(): void
    {
        $store = new TelemetryStore();

        $event = (new StoreMapper($store))->map(new SentryStore(['event_id' => 'x1', 'message' => 'Boom'], project: 'shop'));

        Assert::same($event?->projectKey(), 'shop');
        Assert::same($store->traces(), []);
    }

    public function testStoreEndpointErrorHasFacetsOfItsFields(): void
    {
        $event = (new StoreMapper(new TelemetryStore()))->map(new SentryStore([
            'message' => 'Boom',
            'level' => 'warning',
            'server_name' => 'web-1',
            'user' => ['ip_address' => '10.0.0.1'],
            'tags' => [['feature', 'checkout']],
        ]));

        Assert::same($event?->facets, [
            'host' => ['web-1'],
            'level' => ['warning'],
            'user' => ['10.0.0.1'],
            'tag:feature' => ['checkout'],
        ]);
    }
}
