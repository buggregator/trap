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
use Buggregator\Trap\Server\Telemetry\Feedback;
use Buggregator\Trap\Server\Telemetry\Insight\TraceInsights;
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
        $this->api = new ApiController($this->events, $this->telemetry, new TraceInsights());
        $this->now = \microtime(true);
    }

    public function testCountsFollowProjectAndPeriod(): void
    {
        $this->fill();

        Assert::same($this->api->counts(), ['exceptions' => 3, 'traces' => 2, 'logs' => 3, 'metrics' => 2, 'feedback' => 0]);
        Assert::same($this->api->counts(project: 'shop'), ['exceptions' => 2, 'traces' => 1, 'logs' => 2, 'metrics' => 1, 'feedback' => 0]);
        Assert::same($this->api->counts(window: '1h'), ['exceptions' => 2, 'traces' => 1, 'logs' => 2, 'metrics' => 1, 'feedback' => 0]);
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

    public function testListedTraceLastsUntilItsLatestSpanEnds(): void
    {
        $now = $this->now;
        $api = new Batch('shop');
        $api->transactions = [new Transaction('root', 'shop', self::T1, 'r', null, 'POST /checkout', 'http.server', 'ok', $now - 10, $now - 9.8, Source::Sentry, 'shop-api')];
        $api->spans = [new Span('shop', self::T1, 'q', 'r', 'root', 'queue.publish', 'order', 'ok', $now - 9.9, $now - 9.89, Source::Sentry, 'shop-api')];
        $this->telemetry->add($api);
        $worker = new Batch('worker');
        $worker->transactions = [new Transaction('job', 'worker', self::T1, 'w', 'q', 'order', 'queue.process', 'ok', $now - 9.6, $now - 9.47, Source::Sentry, 'shop-worker')];
        $worker->spans = [
            new Span('worker', self::T1, 'db', 'w', 'job', 'db.sql.query', 'INSERT', 'internal_error', $now - 9.48, $now - 9.472, Source::Sentry, 'shop-worker'),
        ];
        $this->telemetry->add($worker);

        $row = $this->api->traces()['data'][0];
        $shop = $this->api->traces(project: 'shop')['data'][0];

        Assert::same([$row['duration_ms'], $row['span_count'], $row['error_count']], [530, 2, 1]);
        $db = \array_column($row['preview_spans'], null, 'span_id')['db'];
        Assert::same($db['start_offset_ms'] + $db['duration_ms'], 528);
        Assert::same([$shop['duration_ms'], $shop['span_count'], $shop['error_count']], [200, 1, 0]);
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

    public function testTraceTellsRepeatedQueries(): void
    {
        $now = $this->now;
        $shop = new Batch('shop');
        $shop->transactions = [
            new Transaction('root', 'shop', self::T1, 'r1', null, 'GET /orders', 'http.server', 'ok', $now - 1, $now, Source::Sentry, 'shop-api'),
        ];
        for ($i = 1; $i <= 5; ++$i) {
            $data = $i === 4 ? ['code.filepath' => 'app/Orders.php', 'code.lineno' => 7] : [];
            $shop->spans[] = new Span('shop', self::T1, "q$i", 'r1', 'root', 'db.sql.query', "select * from items where order_id = $i", 'ok', $now - 0.5, $now - 0.499, Source::Sentry, 'shop-api', new Peer('db'), $data);
        }
        $billing = new Batch('billing');
        $billing->transactions = [
            new Transaction('pay', 'billing', self::T1, 'p1', 'r1', 'POST /pay', 'http.server', 'ok', $now - 1, $now, Source::Sentry, 'billing'),
        ];
        $this->telemetry->add($shop);
        $this->telemetry->add($billing);

        $all = $this->api->trace(self::T1);
        $other = $this->api->trace(self::T1, 'billing');

        Assert::count($all['n_plus_one'], 1);
        Assert::same(\array_diff_key($all['n_plus_one'][0], ['total_ms' => true]), [
            'transaction_id' => 'root',
            'query' => 'SELECT * FROM items WHERE order_id = ?',
            'count' => 5,
            'origin' => 'app/Orders.php:7',
            'span_ids' => ['q1', 'q2', 'q3', 'q4', 'q5'],
        ]);
        Assert::numeric($all['n_plus_one'][0]['total_ms'])->between(4.99, 5.01);
        Assert::same($other['n_plus_one'], []);
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

        Assert::same($this->api->counts(), ['exceptions' => 1, 'traces' => 1, 'logs' => 1, 'metrics' => 1, 'feedback' => 0]);
        Assert::notBlank($this->events->get('other'));
    }

    public function testClearsEverything(): void
    {
        $this->fill();

        $this->clear()->clear();

        Assert::same($this->api->counts(), ['exceptions' => 0, 'traces' => 0, 'logs' => 0, 'metrics' => 0, 'feedback' => 0]);
        Assert::notBlank($this->events->get('other'));
    }

    public function testServiceMapDrawsServicesCallsAndExternals(): void
    {
        $this->fill();

        $map = $this->api->serviceMap();

        Assert::same($map['window_minutes'], 60);
        Assert::same($map['bucket_seconds'], 60);
        Assert::same(
            \array_map(static fn(array $node): array => [$node['id'], $node['label'], $node['kind'], $node['request_count']], $map['nodes']),
            [['billing', 'billing', 'service', 1], ['shop-api', 'shop-api', 'service', 1], ['pg', 'DB: pg', 'external', 1]],
        );
        Assert::same(
            \array_map(static fn(array $edge): array => [$edge['source'], $edge['target'], $edge['op_type'], $edge['error_count']], $map['edges']),
            [['billing', 'pg', 'db', 0], ['shop-api', 'billing', 'http', 1]],
        );
        $edge = $map['edges'][1];
        Assert::same([$edge['avg_duration_ms'], $edge['p95_duration_ms'], $edge['error_rate'], $edge['trace_count']], [700, 700, 1.0, 1]);
        Assert::same($edge['traces'][0]['trace_id'], self::T1);
        Assert::same(\array_sum(\array_column($edge['buckets'], 'count')), 1);
    }

    public function testServiceMapPeriodAndProject(): void
    {
        $this->fill();

        Assert::count($this->api->serviceMap(window: 'all')['nodes'], 4);
        Assert::same(\array_column($this->api->serviceMap(project: 'other', window: 'all')['nodes'], 'id'), ['worker']);
        Assert::same($this->api->serviceMap(window: '30')['window_minutes'], 30);
    }

    public function testServiceMapEdgeListsTraces(): void
    {
        $this->fill();

        $result = $this->api->serviceMapEdge(source: 'shop-api', target: 'billing', opType: 'http');

        Assert::same($result['total'], 1);
        Assert::same([$result['traces'][0]['trace_id'], $result['traces'][0]['duration_ms'], $result['traces'][0]['is_error']], [self::T1, 700, true]);
        Assert::same($this->api->serviceMapEdge(source: 'shop-api', target: 'billing', opType: 'queue')['total'], 0);
        Assert::same($this->api->serviceMapEdge(source: 'shop-api', target: 'billing', offset: 1)['traces'], []);
        Assert::instanceOf($this->api->serviceMapEdge(source: 'shop-api'), Error::class);
    }

    public function testSequenceListsCallsInTimeOrder(): void
    {
        $this->fill();

        $result = $this->api->sequence(self::T1);

        Assert::same($result['services'], ['shop-api', 'billing']);
        [$http, $db] = $result['calls'];
        Assert::same([$http['from'], $http['to'], $http['op_type'], $http['is_error']], ['shop-api', 'billing', 'http', true]);
        Assert::same($http['callee']['transaction_id'], 'pay-txn');
        Assert::same($http['late_ms'], -100);
        Assert::same([$db['from'], $db['to'], $db['callee'], $db['late_ms']], ['billing', 'pg', null, null]);
        Assert::instanceOf($this->api->sequence('ffffffffffffffffffffffffffffffff'), Error::class);
    }

    public function testCalleeOfSpansOnlyAndOfTransactionSpans(): void
    {
        $now = $this->now;
        $batch = new Batch('default');
        $batch->transactions = [
            new Transaction('root', 'default', self::T1, 'r', null, 'GET /', 'http.server', 'ok', $now - 10, $now - 9, Source::Sentry, 'web'),
            // A transaction without a service name, whose span names the service
            new Transaction('anon', 'default', self::T1, 'a', 'c2', 'job', 'task', 'ok', $now - 9.5, $now - 9.4, Source::Sentry, null),
        ];
        $batch->spans = [
            new Span('default', self::T1, 'c1', 'r', 'root', 'http.client', 'GET http://api/', 'ok', $now - 9.9, $now - 9.8, Source::Sentry, 'web', new Peer('http', 'api')),
            // A v2 / OTel span of another service, no transaction
            new Span('default', self::T1, 'api1', 'c1', null, 'http.server', 'GET /', 'error', $now - 9.89, $now - 9.79, Source::Otel, 'api'),
            new Span('default', self::T1, 'c2', 'r', 'root', 'http.client', 'POST http://jobs/', 'ok', $now - 9.6, $now - 9.3, Source::Sentry, 'web', new Peer('http', 'jobs')),
            new Span('default', self::T1, 'j1', 'a', 'anon', 'task', 'run', 'ok', $now - 9.5, $now - 9.4, Source::Sentry, 'jobs-worker'),
        ];
        $this->telemetry->add($batch);

        $sequence = $this->api->sequence(self::T1);
        $map = $this->api->serviceMap();

        Assert::same(\array_column($sequence['calls'], 'to'), ['api', 'jobs']);
        Assert::same($sequence['calls'][0]['callee']['transaction_id'], null);
        Assert::true($sequence['calls'][0]['callee']['is_error']);
        Assert::same($sequence['calls'][0]['late_ms'], 10);
        Assert::same(\array_column($map['edges'], 'target'), ['api', 'jobs-worker']);
    }

    public function testEdgeStatsAndTraceOrder(): void
    {
        $now = $this->now;
        $batch = new Batch('default');
        $traces = ['a' => [-30.0, true], 'b' => [-10.0, false], 'c' => [-20.0, false]];
        foreach ($traces as $id => [$start, $error]) {
            $traceId = \str_repeat($id, 32);
            $batch->transactions[] = new Transaction("t$id", 'default', $traceId, "r$id", null, 'GET /', 'http.server', 'ok', $now + $start, $now + $start + 1, Source::Sentry, 'web');
            $batch->spans[] = new Span('default', $traceId, "s$id", "r$id", "t$id", 'db', 'SELECT', $error ? 'internal_error' : 'ok', $now + $start, $now + $start + 0.005, Source::Sentry, 'web', new Peer('db', 'pg'));
        }
        // Durations 1..17 ms in one more trace: with the three 5 ms ones, the nearest-rank p95 of 20 is the 19th
        for ($i = 1; $i <= 17; ++$i) {
            $batch->spans[] = new Span('default', \str_repeat('d', 32), "d$i", null, null, 'db', 'SELECT', 'ok', $now - 5, $now - 5 + $i / 1000, Source::Sentry, 'web', new Peer('db', 'pg'));
        }
        $this->telemetry->add($batch);

        $edge = $this->api->serviceMap()['edges'][0];
        $traces = $this->api->serviceMapEdge(source: 'web', target: 'pg');

        Assert::same([$edge['request_count'], $edge['error_count'], $edge['avg_duration_ms'], $edge['p95_duration_ms']], [20, 1, 8, 16]);
        Assert::same(\array_column($traces['traces'], 'trace_id'), [\str_repeat('a', 32), \str_repeat('d', 32), \str_repeat('b', 32), \str_repeat('c', 32)]);
    }

    public function testServiceMapSurvivesFarApartTimes(): void
    {
        $batch = new Batch('default');
        for ($i = 0; $i < 2; ++$i) {
            $batch->spans[] = new Span('default', self::T1, "c$i", null, null, 'db', 'SELECT', 'ok', -9e15, $this->now - 1, Source::Sentry, 'web', new Peer('db', 'pg'));
        }
        $this->telemetry->add($batch);

        $edge = $this->api->serviceMap(window: 'all')['edges'][0];

        Assert::same([$edge['avg_duration_ms'], $edge['p95_duration_ms']], [Span::MAX_DURATION_MS, Span::MAX_DURATION_MS]);
    }

    public function testLargeTraceIsMappedFast(): void
    {
        $now = $this->now;
        $batch = new Batch('default');
        for ($i = 0; $i < 5_000; ++$i) {
            $batch->spans[] = new Span('default', self::T1, "c$i", 'r', null, 'http.client', 'GET http://api/', 'ok', $now - 9, $now - 8, Source::Sentry, 'web', new Peer('http', 'api'));
            $batch->spans[] = new Span('default', self::T1, "s$i", "c$i", null, 'http.server', 'GET /', 'ok', $now - 9, $now - 8, Source::Otel, 'api');
        }
        $this->telemetry->add($batch);
        $started = \microtime(true);

        $map = $this->api->serviceMap();
        $sequence = $this->api->sequence(self::T1);

        Assert::numeric(\microtime(true) - $started)->lessThan(2.0);
        Assert::same($map['edges'][0]['request_count'], 5_000);
        Assert::count($sequence['calls'], 5_000);
    }

    public function testScopeReadsTheQuery(): void
    {
        Assert::same(Scope::fromQuery(window: '2h', now: 10000.0)->from, 2800.0);
        Assert::same(Scope::fromQuery(window: '15m', from: '100', now: 10000.0)->from, 100.0);
        Assert::same(Scope::fromQuery(to: '1970-01-01T00:01:00Z')->to, 60.0);
        Assert::null(Scope::fromQuery(window: 'all')->from);
        Assert::null(Scope::fromQuery(project: ' ')->project);
        Assert::same(Scope::parseWindow('99999999999999999999h'), 3_600_000_000);
        Assert::same(Json::pageNumber(\PHP_INT_MAX), 1_000_000);
        Assert::false((new Scope(from: 1.0))->contains('default', null));
        Assert::true((new Scope())->contains('default', null));
    }

    public function testListsFeedbackNewestFirstByProjectPeriodAndError(): void
    {
        $this->fillFeedback();

        $all = $this->api->feedback();
        Assert::same($all['meta'], ['total' => 3, 'page' => 1, 'limit' => 100]);
        Assert::same(\array_column($all['data'], 'message'), ['Later', 'Billing', 'Broken']);
        Assert::same(\array_keys($all['data'][2]), [
            'id', 'project', 'event_id', 'feedback_id', 'name', 'email', 'message', 'url', 'source', 'received_at', 'payload',
        ]);
        Assert::same($all['data'][2]['received_at'], Json::time($this->now - 7200));

        Assert::same(\array_column($this->api->feedback(project: 'shop')['data'], 'message'), ['Later', 'Broken']);
        Assert::same(\array_column($this->api->feedback(window: '1h')['data'], 'message'), ['Later', 'Billing']);
        Assert::same(
            \array_column($this->api->feedback(eventId: '5A9D3B2C-1E4F-4A6B-8C7D-0E1F2A3B4C5D')['data'], 'message'),
            ['Broken'],
        );
        Assert::same($this->api->feedback(limit: 1, page: 2)['data'][0]['message'], 'Billing');
        Assert::same($this->api->counts(project: 'shop')['feedback'], 2);
        Assert::same($this->api->counts(window: '1h')['feedback'], 2);
    }

    public function testClearDeletesFeedbackOfProjectButOnPinnedErrors(): void
    {
        $this->fillFeedback();
        $this->events->add(new Event('e9', 'sentry', ['event_id' => '5a9d3b2c1e4f4a6b8c7d0e1f2a3b4c5d'], $this->now, 'shop'));
        $this->events->pin('e9');

        $this->clear()->clear(project: 'shop', keep_pinned: '1');
        Assert::same(\array_column($this->api->feedback()['data'], 'message'), ['Billing', 'Broken']);

        $this->clear()->clear(project: 'shop');
        Assert::same(\array_column($this->api->feedback()['data'], 'message'), ['Billing']);

        $this->clear()->clear();
        Assert::same($this->api->feedback()['data'], []);
    }

    private function fillFeedback(): void
    {
        $add = function (string $project, string $eventId, string $message, float $time): void {
            $batch = new Batch($project);
            $batch->feedback[] = new Feedback(
                "f-$message",
                $project,
                $eventId,
                '',
                'Ann',
                'ann@example.com',
                $message,
                'http://shop.test/',
                Feedback::SOURCE_FEEDBACK,
                $time,
            );
            $this->telemetry->add($batch);
        };
        $add('shop', '5a9d3b2c1e4f4a6b8c7d0e1f2a3b4c5d', 'Broken', $this->now - 7200);
        $add('billing', '', 'Billing', $this->now - 120);
        $add('shop', '', 'Later', $this->now - 60);
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
        $this->events->add(new Event('f', 'sentry', ['type' => 'feedback', 'contexts' => ['feedback' => ['message' => 'Hi']]], $now - 30, 'shop'));
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
