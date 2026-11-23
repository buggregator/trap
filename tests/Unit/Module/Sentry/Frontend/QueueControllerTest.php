<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Sentry\Frontend;

use Buggregator\Trap\Module\Frontend\Message\Error;
use Buggregator\Trap\Module\Sentry\Frontend\Api\Json;
use Buggregator\Trap\Module\Sentry\Frontend\ClearController;
use Buggregator\Trap\Module\Sentry\Frontend\QueueController;
use Buggregator\Trap\Module\Sentry\Queue\Job;
use Buggregator\Trap\Module\Sentry\Queue\QueueJobs;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Server\Telemetry\Batch;
use Buggregator\Trap\Server\Telemetry\Source;
use Buggregator\Trap\Server\Telemetry\Span;
use Buggregator\Trap\Server\Telemetry\TelemetryStore;
use Buggregator\Trap\Server\Telemetry\Transaction;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Covers(QueueController::class)]
#[Covers(QueueJobs::class)]
#[Covers(Job::class)]
#[Test]
final class QueueControllerTest
{
    private const T1 = '11111111111111111111111111111111';
    private const T2 = '22222222222222222222222222222222';
    private const T3 = '33333333333333333333333333333333';

    private EventStore $events;
    private TelemetryStore $telemetry;
    private QueueController $api;
    private float $now;

    #[BeforeTest]
    public function createStores(): void
    {
        $this->events = new EventStore();
        $this->telemetry = new TelemetryStore();
        $this->api = new QueueController($this->events, $this->telemetry);
        $this->now = \microtime(true);
    }

    public function testListsJobsLatestFinishedFirst(): void
    {
        $this->fill();

        $result = $this->api->list();

        Assert::same($result['meta'], ['total' => 5, 'page' => 1, 'limit' => 50]);
        Assert::same(\array_column($result['data'], 'id'), ['bad', 'shop/m3', 'shop/orders/m2', 'shop/m1', 'other-tx']);
        $job = $result['data'][3];
        Assert::same([$job['job'], $job['queue'], $job['connection'], $job['status']], ['App\Jobs\SendInvoice', 'emails', 'redis', 'ok']);
        Assert::same([$job['failed'], $job['attempts'], $job['failed_attempts'], $job['retried']], [false, 2, 1, true]);
        Assert::same([$job['duration_ms'], $job['latency_ms']], [2000, 30500]);
        Assert::same($job['started_at'], Json::time($this->now - 60));
        Assert::same($job['finished_at'], Json::time($this->now - 28));
        Assert::same([$job['transaction_id'], $job['trace_id'], $job['project']], ['a2', self::T1, 'shop']);
    }

    public function testCountsAttemptsAnSdkReportsBeyondTheStoredOnes(): void
    {
        $this->fill();

        $job = $this->api->detail('shop/m3');

        Assert::same([$job['attempts'], $job['failed_attempts'], $job['retried']], [2, 0, true]);
        Assert::same(\array_column($job['attempt_list'], 'attempt'), [2]);
        Assert::same($job['attempt_list'][0]['transaction_id'], 'celery-txn');
    }

    public function testSummaryHasTheNumbersOfTheList(): void
    {
        $this->fill();

        $summary = $this->api->summary();

        Assert::same($summary, [
            'jobs' => 5,
            'failed' => 2,
            'retried' => 2,
            'attempts' => 7,
            'avg_duration_ms' => 620.0,
            'queues' => [
                ['queue' => '', 'jobs' => 2, 'failed' => 2],
                ['queue' => 'celery', 'jobs' => 1, 'failed' => 0],
                ['queue' => 'emails', 'jobs' => 1, 'failed' => 0],
                ['queue' => 'orders', 'jobs' => 1, 'failed' => 0],
            ],
        ]);
        $list = $this->api->list(limit: 500)['data'];
        Assert::same(\array_sum(\array_column($list, 'attempts')), $summary['attempts']);
        Assert::same(\count(\array_filter(\array_column($list, 'failed'))), $summary['failed']);
    }

    public function testFiltersByProjectPeriodQueueJobAndStatus(): void
    {
        $this->fill();

        $ids = fn(mixed ...$args): array => \array_column($this->api->list(...$args)['data'], 'id');
        Assert::same($ids(project: 'other'), ['other-tx']);
        Assert::same($ids(window: '1h'), ['bad', 'shop/m3', 'shop/orders/m2', 'shop/m1']);
        Assert::same($ids(from: (string) ($this->now - 7300), to: (string) ($this->now - 7000)), ['other-tx']);
        Assert::same($ids(queue: 'emails'), ['shop/m1']);
        Assert::same($ids(job: 'tasks.send_mail'), ['shop/m3']);
        Assert::same($ids(status: 'failed'), ['bad', 'other-tx']);
        Assert::same($ids(status: 'ok'), ['shop/m3', 'shop/orders/m2', 'shop/m1']);
        Assert::same($ids(status: 'retried'), ['shop/m3', 'shop/m1']);
        Assert::same($this->api->summary(project: 'shop', window: '1h', status: 'failed')['jobs'], 1);
        Assert::same($ids(limit: 2, page: 2), ['shop/orders/m2', 'shop/m1']);
    }

    public function testRejectsAnUnknownStatus(): void
    {
        $this->fill();

        $error = $this->api->list(status: 'running');

        Assert::instanceOf($error, Error::class);
        Assert::same($error->httpCode(), 400);
        Assert::instanceOf($this->api->summary(status: 'running'), Error::class);
    }

    public function testShowsAJobWithItsAttemptsErrorsAndDispatcher(): void
    {
        $this->fill();

        $job = $this->api->detail('shop/m1');

        Assert::same($job['id'], 'shop/m1');
        [$first, $second] = $job['attempt_list'];
        Assert::same(
            [$first['transaction_id'], $first['attempt'], $first['status'], $first['failed'], $first['latency_ms'], $first['gap_ms']],
            ['a1', 1, 'internal_error', true, 10, null],
        );
        Assert::same([$second['attempt'], $second['failed'], $second['gap_ms'], $second['latency_ms']], [2, false, 29000, 30500]);
        Assert::same($first['errors'], [[
            'id' => 'err1',
            'exception_type' => 'RuntimeException',
            'exception_value' => 'SMTP down',
            'event_ts' => Json::time($this->now - 59),
        ]]);
        Assert::same($second['errors'], []);
        Assert::same($job['dispatched_by'], [
            'transaction_id' => 'req',
            'trace_id' => self::T1,
            'transaction_name' => 'POST /invoices',
            'start_ts' => Json::time($this->now - 61),
        ]);
    }

    public function testFindsTheDispatcherOfAnOtelJobByItsParentSpan(): void
    {
        $this->fill();

        Assert::same($this->api->detail('shop/orders/m2')['dispatched_by']['transaction_id'], 'api');
        Assert::null($this->api->detail('other-tx')['dispatched_by']);
    }

    public function testOpensAJobByItsEncodedIdOnly(): void
    {
        $this->fill();

        Assert::same($this->api->detail('other-tx')['project'], 'other');
        Assert::same($this->api->detail('bad', project: 'shop')['attempt_list'][0]['latency_ms'], null);
        $missing = $this->api->detail('nope');
        Assert::instanceOf($missing, Error::class);
        Assert::same($missing->httpCode(), 404);
        Assert::instanceOf($this->api->detail('shop/m1', project: 'other'), Error::class);

        $batch = new Batch('shop');
        $batch->transactions = [$this->attempt('slash', self::T3, 'x', null, ['messaging.message.id' => 'q/1'])];
        $this->telemetry->add($batch);
        Assert::same($this->api->detail('shop%2Fq%252F1')['message_id'], 'q/1');
    }

    public function testClearingTheSentryPagesClearsTheJobs(): void
    {
        $this->fill();

        (new ClearController(new Logger(), $this->events, $this->telemetry))->clear('shop');

        Assert::same(\array_column($this->api->list()['data'], 'id'), ['other-tx']);
        Assert::same($this->api->summary()['jobs'], 1);
    }

    /**
     * Shop: a Laravel job `m1` that failed once, then succeeded, queued by `POST /invoices`; an OTel job `m2`;
     * a Celery span whose first try Trap missed; a job of malformed data. Other: a failed job two hours old.
     */
    private function fill(): void
    {
        $now = $this->now;
        $laravel = static fn(int $retries, float $latency): array => [
            'messaging.system' => 'laravel',
            'messaging.message.id' => 'm1',
            'messaging.destination.name' => 'emails',
            'messaging.destination.connection' => 'redis',
            'messaging.message.retry.count' => $retries,
            'messaging.message.receive.latency' => $latency,
        ];

        $shop = new Batch('shop');
        $shop->transactions = [
            new Transaction('req', 'shop', self::T1, 'r', null, 'POST /invoices', 'http.server', 'ok', $now - 61, $now - 60.5, Source::Sentry, 'api'),
            $this->attempt('a2', self::T1, 'a2s', 'pub', $laravel(1, 30.5), $now - 30, $now - 28),
            $this->attempt('a1', self::T1, 'a1s', 'pub', $laravel(0, 0.01), $now - 60, $now - 59, 'internal_error'),
            new Transaction('api', 'shop', self::T2, 'api-span', null, 'POST /orders', 'server', 'ok', $now - 21, $now - 20, Source::Otel, 'gateway'),
            new Transaction(self::T2 . '-c', 'shop', self::T2, 'c', 'prod', 'process orders', 'queue.process', 'ok', $now - 20, $now - 19.9, Source::Otel, 'orders', [
                'messaging.system' => 'rabbitmq',
                'messaging.operation.type' => 'process',
                'messaging.destination.name' => 'orders',
                'messaging.message.id' => 'm2',
                'messaging.message.receive.latency' => 15,
            ]),
            $this->attempt('bad', self::T3, 'b', null, [
                'messaging.message.id' => [],
                'messaging.message.retry.count' => -3,
                'messaging.message.receive.latency' => 'later',
            ], $now - 5, $now - 4.9, 'unknown_error'),
            new Transaction('celery-txn', 'shop', self::T3, 'ct', null, 'tasks.send_mail', 'queue.task.celery', 'ok', $now - 10, $now - 9, Source::Sentry, 'worker'),
        ];
        $shop->spans = [
            new Span('shop', self::T1, 'pub', 'r', 'req', 'queue.publish', 'App\Jobs\SendInvoice', 'ok', $now - 60.9, $now - 60.8, Source::Sentry, 'api', attributes: ['messaging.message.id' => 'm1']),
            new Span('shop', self::T2, 'prod', 'api-span', null, 'queue.publish', 'publish orders', 'ok', $now - 20.5, $now - 20.4, Source::Otel, 'gateway', attributes: ['messaging.system' => 'rabbitmq']),
            new Span('shop', self::T3, 'cs', 'ct', 'celery-txn', 'queue.process', 'tasks.send_mail', 'ok', $now - 10, $now - 9.2, Source::Sentry, 'worker', attributes: [
                'messaging.system' => 'celery',
                'messaging.message.id' => 'm3',
                'messaging.destination.name' => 'celery',
                'messaging.message.retry.count' => 1,
            ]),
        ];
        $this->telemetry->add($shop);

        $other = new Batch('other');
        $other->transactions = [$this->attempt('other-tx', self::T3, 'o', null, [], $now - 7200, $now - 7199.9, 'internal_error', 'other')];
        $this->telemetry->add($other);

        $this->events->add(new Event('err1', 'sentry', [
            'exception' => ['values' => [['type' => 'RuntimeException', 'value' => 'SMTP down']]],
            'contexts' => ['trace' => ['trace_id' => self::T1, 'span_id' => 'a1s']],
        ], $now - 59, 'shop'));
        $this->events->add(new Event('err2', 'sentry', [
            'exception' => ['values' => [['type' => 'LogicException', 'value' => 'elsewhere']]],
            'contexts' => ['trace' => ['trace_id' => self::T1, 'span_id' => 'r']],
        ], $now - 45, 'shop'));
    }

    /**
     * @param non-empty-string $id
     * @param non-empty-string $traceId
     */
    private function attempt(
        string $id,
        string $traceId,
        string $spanId,
        ?string $parent,
        array $attributes,
        ?float $start = null,
        ?float $end = null,
        string $status = 'ok',
        string $project = 'shop',
    ): Transaction {
        return new Transaction(
            $id,
            $project,
            $traceId,
            $spanId,
            $parent,
            'App\Jobs\SendInvoice',
            'queue.process',
            $status,
            $start ?? $this->now - 1,
            $end ?? $this->now - 0.5,
            Source::Sentry,
            'worker',
            $attributes,
        );
    }
}
