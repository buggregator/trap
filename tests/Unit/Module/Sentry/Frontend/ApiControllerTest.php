<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Sentry\Frontend;

use Buggregator\Trap\Module\Frontend\Message\Error;
use Buggregator\Trap\Module\Sentry\Frontend\Api\ErrorEvent;
use Buggregator\Trap\Module\Sentry\Frontend\Api\Json;
use Buggregator\Trap\Module\Sentry\Frontend\Api\Scope;
use Buggregator\Trap\Module\Sentry\Frontend\Api\TraceView;
use Buggregator\Trap\Module\Sentry\Frontend\ApiController;
use Buggregator\Trap\Module\Sentry\Frontend\ClearController;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Server\Telemetry\Batch;
use Buggregator\Trap\Server\Telemetry\Log;
use Buggregator\Trap\Server\Telemetry\Metric;
use Buggregator\Trap\Server\Telemetry\Peer;
use Buggregator\Trap\Server\Telemetry\Source;
use Buggregator\Trap\Server\Telemetry\Span;
use Buggregator\Trap\Server\Telemetry\TelemetryStore;
use Buggregator\Trap\Server\Telemetry\Transaction;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Covers(ApiController::class)]
#[Covers(ClearController::class)]
#[Covers(TraceView::class)]
#[Covers(ErrorEvent::class)]
#[Covers(Scope::class)]
#[Covers(Json::class)]
#[Test]
final class ApiControllerTest
{
    private const T1 = '5b8efff798038103d269b633813fc60c';
    private const T2 = '0102030405060708090a0b0c0d0e0f10';

    private EventStore $events;
    private TelemetryStore $telemetry;
    private ApiController $api;
    private float $now;

    #[BeforeTest]
    public function createStores(): void
    {
        $this->events = new EventStore();
        $this->telemetry = new TelemetryStore();
        $this->api = new ApiController($this->events, $this->telemetry);
        $this->now = \microtime(true);
    }

    public function testCountsFollowProjectAndPeriod(): void
    {
        $this->fill();

        Assert::same($this->api->counts(), ['exceptions' => 3, 'traces' => 2, 'logs' => 3, 'metrics' => 2]);
        Assert::same($this->api->counts(project: 'shop'), ['exceptions' => 2, 'traces' => 1, 'logs' => 2, 'metrics' => 1]);
        Assert::same($this->api->counts(window: '1h'), ['exceptions' => 2, 'traces' => 1, 'logs' => 2, 'metrics' => 1]);
        Assert::same($this->api->counts(window: 'all'), $this->api->counts());
    }

    public function testGroupsExceptionsByFingerprint(): void
    {
        $this->fill();

        $result = $this->api->exceptions(grouped: 'true');

        Assert::same($result['meta'], ['total' => 2, 'page' => 1, 'limit' => 50]);
        [$first, $second] = $result['data'];
        Assert::same(
            [$first['exception_type'], $first['exception_value'], $first['count'], $first['handled'], $first['sample_event_id']],
            ['RuntimeException', 'Boom', 2, false, 'e2'],
        );
        Assert::same($first['first_seen'], Json::time($this->now - 7200));
        Assert::same($first['last_seen'], Json::time($this->now - 60));
        Assert::string($first['fingerprint'])->matchesRegex('/^[0-9a-f]{16}$/');
        Assert::same([$second['exception_type'], $second['exception_value'], $second['level']], ['', 'Hello', 'info']);
    }

    public function testFiltersExceptions(): void
    {
        $this->fill();

        Assert::same($this->api->exceptions(grouped: 'true', level: 'info')['meta']['total'], 1);
        Assert::same($this->api->exceptions(grouped: 'true', handled: 'true')['meta']['total'], 1);
        Assert::same($this->api->exceptions(grouped: 'true', handled: 'false')['meta']['total'], 1);
        Assert::same($this->api->exceptions(grouped: 'true', project: 'shop', window: '1h')['meta']['total'], 2);
        Assert::same($this->api->exceptions(grouped: 'true', project: 'other', window: '1h')['meta']['total'], 0);
    }

    public function testListsExceptionsNewestFirst(): void
    {
        $this->fill();

        $result = $this->api->exceptions(limit: 2, page: 2);

        Assert::same($result['meta'], ['total' => 3, 'page' => 2, 'limit' => 2]);
        Assert::same($result['data'][0]['id'], 'e1');
        Assert::same($result['data'][0]['occurrence_count'], 2);
        Assert::same($result['data'][0]['trace_id'], self::T1);
    }

    public function testListsOneRowPerTraceForItsRoot(): void
    {
        $this->fill();

        $result = $this->api->traces();

        Assert::same($result['meta']['total'], 2);
        $row = $result['data'][0];
        Assert::same([$row['trace_id'], $row['transaction_id'], $row['transaction_name']], [self::T1, 'root', 'GET /orders']);
        Assert::same([$row['duration_ms'], $row['span_count'], $row['error_count']], [1000, 2, 1]);
        Assert::same($row['sources'], ['sentry', 'otel']);
        Assert::same($row['services'], ['shop-api', 'billing']);
        Assert::same(\array_column($row['preview_spans'], 'span_id'), ['http', 'db']);
        Assert::same($row['preview_spans'][0]['start_offset_ms'], 200);
        Assert::same($result['data'][1]['trace_id'], self::T2);
    }

    public function testFiltersTraces(): void
    {
        $this->fill();

        Assert::same(\array_column($this->api->traces(source: 'otel')['data'], 'trace_id'), [self::T1]);
        Assert::same(\array_column($this->api->traces(service: 'billing')['data'], 'trace_id'), [self::T1]);
        Assert::same(\array_column($this->api->traces(project: 'other')['data'], 'trace_id'), [self::T2]);
        Assert::same(\array_column($this->api->traces(window: '1h')['data'], 'trace_id'), [self::T1]);
        $error = $this->api->traces(source: 'zipkin');
        Assert::instanceOf($error, Error::class);
        Assert::same($error->httpCode(), 400);
    }

    public function testShowsTraceWithEntrySpansOfOtherServices(): void
    {
        $this->fill();

        $result = $this->api->trace(self::T1);

        Assert::same($result['transaction']['id'], 'root');
        Assert::same($result['transaction']['span_id'], 'r1');
        Assert::same($result['transaction']['sources'], ['sentry', 'otel']);
        Assert::same(\array_column($result['spans'], 'span_id'), ['http', 'pay', 'db']);
        Assert::true($result['spans'][1]['is_transaction']);
        Assert::same($result['spans'][1]['transaction_id'], 'pay-txn');
        Assert::same($result['spans'][1]['attributes'], ['otel.kind' => 'server']);
        Assert::same($result['spans'][2]['peer_address'], 'pg');
        Assert::null($result['spans'][0]['attributes']);
        Assert::same(\array_column($result['related_errors'], 'id'), ['e2', 'e1']);
        Assert::same($result['related_log_count'], 2);
    }

    public function testUnknownTraceIsNotFound(): void
    {
        $this->fill();

        $result = $this->api->trace('ffffffffffffffffffffffffffffffff');

        Assert::instanceOf($result, Error::class);
        Assert::same($result->httpCode(), 404);
        Assert::same($result->jsonSerialize(), ['message' => 'trace not found', 'code' => 404]);
    }

    public function testFiltersLogs(): void
    {
        $this->fill();

        $all = $this->api->logs();
        $errors = $this->api->logs(level: 'warn, error');
        $related = $this->api->logs(traceId: self::T1);

        Assert::same(\array_column($all['data'], 'body'), ['newest', 'trace log', 'old']);
        Assert::same(\array_column($errors['data'], 'body'), ['newest', 'old']);
        Assert::same(\array_column($related['data'], 'trace_exists'), [true, true]);
        Assert::same(\array_column($this->api->logs(source: 'otel')['data'], 'body'), ['trace log']);
        Assert::same($all['data'][1]['service_name'], 'billing');
        Assert::instanceOf($this->api->logs(source: 'x'), Error::class);
    }

    public function testFiltersMetrics(): void
    {
        $this->fill();

        Assert::same(\array_column($this->api->metrics()['data'], 'name'), ['http.requests', 'queue.depth']);
        Assert::same(\array_column($this->api->metrics(search: 'HTTP')['data'], 'name'), ['http.requests']);
        Assert::same(\array_column($this->api->metrics(type: 'gauge')['data'], 'name'), ['queue.depth']);
        Assert::same(\array_column($this->api->metrics(name: 'queue.depth', traceId: self::T1)['data'], 'name'), []);
        Assert::same($this->api->metrics()['data'][0]['value'], 3.0);
    }

    public function testClearsOneProject(): void
    {
        $this->fill();

        $this->clear()->clear(project: 'shop');

        Assert::same($this->api->counts(), ['exceptions' => 1, 'traces' => 1, 'logs' => 1, 'metrics' => 1]);
        Assert::notBlank($this->events->get('other'));
    }

    public function testClearsEverything(): void
    {
        $this->fill();

        $this->clear()->clear();

        Assert::same($this->api->counts(), ['exceptions' => 0, 'traces' => 0, 'logs' => 0, 'metrics' => 0]);
        Assert::notBlank($this->events->get('other'));
    }

    public function testScopeReadsTheQuery(): void
    {
        Assert::same(Scope::fromQuery(window: '2h', now: 10000.0)->from, 2800.0);
        Assert::same(Scope::fromQuery(window: '15m', from: '100', now: 10000.0)->from, 100.0);
        Assert::same(Scope::fromQuery(to: '1970-01-01T00:01:00Z')->to, 60.0);
        Assert::null(Scope::fromQuery(window: 'all')->from);
        Assert::null(Scope::fromQuery(project: ' ')->project);
        Assert::false((new Scope(from: 1.0))->contains('default', null));
        Assert::true((new Scope())->contains('default', null));
    }

    private function clear(): ClearController
    {
        return new ClearController(new Logger(), $this->events, $this->telemetry);
    }

    /**
     * Shop: trace T1 (Sentry root, OTel service), errors, logs, metric in the last hour.
     * Other: trace T2, an error and a log two hours old. Plus a non-Sentry event.
     */
    private function fill(): void
    {
        $now = $this->now;
        $exception = static fn(bool $handled): array => [
            'exception' => ['values' => [['type' => 'RuntimeException', 'value' => 'Boom', 'mechanism' => ['handled' => $handled]]]],
            'contexts' => ['trace' => ['trace_id' => self::T1]],
        ];
        $this->events->add(new Event('e1', 'sentry', $exception(true), $now - 7200, 'other'));
        $this->events->add(new Event('e2', 'sentry', $exception(false) + ['level' => 'fatal'], $now - 60, 'shop'));
        $this->events->add(new Event('e3', 'sentry', ['message' => ['formatted' => 'Hello'], 'level' => 'info'], $now - 30, 'shop'));
        $this->events->add(new Event('t', 'sentry', ['type' => 'transaction', 'spans' => []], $now - 30, 'shop'));
        $this->events->add(new Event('other', 'var-dump', ['x' => 1], $now - 30, 'shop'));

        $shop = new Batch('shop');
        $shop->transactions = [
            new Transaction('pay-txn', 'shop', self::T1, 'pay', 'http', 'POST /pay', 'http.server', 'ok', $now - 99.7, $now - 99.2, Source::Otel, 'billing', ['otel.kind' => 'server']),
            new Transaction('root', 'shop', self::T1, 'r1', null, 'GET /orders', 'http.server', 'ok', $now - 100, $now - 99, Source::Sentry, 'shop-api'),
        ];
        $shop->spans = [
            new Span('shop', self::T1, 'http', 'r1', 'root', 'http.client', 'POST http://billing/pay', 'internal_error', $now - 99.8, $now - 99.1, Source::Sentry, 'shop-api', new Peer('http', 'billing')),
            new Span('shop', self::T1, 'db', 'pay', null, 'db', 'INSERT', 'ok', $now - 99.6, $now - 99.5, Source::Otel, 'billing', new Peer('db', 'pg')),
        ];
        $shop->logs = [
            new Log('l2', 'shop', 'info', 'trace log', $now - 99, Source::Otel, self::T1, service: 'billing'),
            new Log('l3', 'shop', 'error', 'newest', $now - 10, Source::Sentry, self::T1),
        ];
        $shop->metrics = [new Metric('m1', 'shop', 'http.requests', 'counter', 3, $now - 5, Source::Sentry, traceId: self::T1)];
        $this->telemetry->add($shop);

        $other = new Batch('other');
        $other->transactions = [
            new Transaction('o', 'other', self::T2, null, null, 'job', 'queue.task', 'ok', $now - 7200, $now - 7199, Source::Sentry, 'worker'),
        ];
        $other->logs = [new Log('l1', 'other', 'warn', 'old', $now - 7200, Source::Sentry)];
        $other->metrics = [new Metric('m2', 'other', 'queue.depth', 'gauge', 1, $now - 7200, Source::Sentry)];
        $this->telemetry->add($other);
    }
}
