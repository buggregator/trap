<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Tracing\Mcp;

use Buggregator\Trap\Module\Mcp\Tools;
use Buggregator\Trap\Module\Sentry\Frame\EnvelopeItem;
use Buggregator\Trap\Module\Sentry\Frame\SentryEnvelope;
use Buggregator\Trap\Module\Sentry\Frontend\EnvelopeMapper;
use Buggregator\Trap\Module\Tracing\Frame\OtlpTraces;
use Buggregator\Trap\Module\Tracing\Frame\Trace\ResourceSpans;
use Buggregator\Trap\Module\Tracing\Frame\Trace\ScopeSpans;
use Buggregator\Trap\Module\Tracing\Frame\Trace\Span;
use Buggregator\Trap\Module\Tracing\Frame\Trace\SpanEvent;
use Buggregator\Trap\Module\Tracing\Frame\Trace\SpanKind;
use Buggregator\Trap\Module\Tracing\Frame\Trace\SpanStatus;
use Buggregator\Trap\Module\Tracing\Frame\Trace\StatusCode;
use Buggregator\Trap\Module\Tracing\Frontend\TelemetryMapper;
use Buggregator\Trap\Module\Tracing\Mcp\ListTraces;
use Buggregator\Trap\Module\Tracing\Mcp\TraceFilter;
use Buggregator\Trap\Module\Tracing\Mcp\TraceSummary;
use Buggregator\Trap\Server\Telemetry\TelemetryStore;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\JsonSchema;
use Buggregator\Trap\Tests\Unit\Module\Mcp\Stub\Trap;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ListTraces::class)]
#[Covers(TraceFilter::class)]
#[Covers(TraceSummary::class)]
final class ListTracesTest
{
    /** Unix nanoseconds of the start of the traces: 0 is an unset time in OTLP */
    private const BASE = 1_700_000_000_000_000_000;

    public function testListsLeastRecentlyUpdatedFirst(): void
    {
        $trap = new Trap();
        $store = $trap->get(TelemetryStore::class);
        self::receive($trap, self::frame('shop', self::span('a', 1, null, 'GET /cart', 0, 2_500_000)));
        self::receive($trap, self::frame('shop', self::span('b', 2, null, 'GET /orders', 0, 1)));
        self::receive($trap, self::frame('db', self::span('a', 3, 1, 'query', 1_000_000, 4_000_000, StatusCode::Error)));

        $result = $trap->get(ListTraces::class)->call([]);

        Assert::same(self::ids($result), [self::traceId('b'), self::traceId('a')]);
        Assert::same(\array_diff_key($result['traces'][1], ['updated' => true]), [
            'id' => self::traceId('a'),
            'name' => 'GET /cart',
            'service' => 'shop',
            'services' => ['shop', 'db'],
            'sources' => ['otel'],
            'projects' => ['default'],
            'spans' => 2,
            'duration_ms' => 4.0,
            'error' => true,
        ]);
        Assert::string($result['traces'][1]['updated'])->matchesRegex('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}\+00:00$/');
        Assert::false($result['traces'][0]['error']);
        Assert::same($result['cursor'], $store->id . ':3');
        Assert::false($result['has_more']);
    }

    public function testCursorGivesOnlyUpdatedTraces(): void
    {
        $trap = new Trap();
        $store = $trap->get(TelemetryStore::class);
        $tool = $trap->get(ListTraces::class);
        self::receive($trap, self::frame('app', self::span('a', 1)));
        self::receive($trap, self::frame('app', self::span('b', 2)));
        $cursor = $tool->call([])['cursor'];
        self::receive($trap, self::frame('app', self::span('c', 3)));
        self::receive($trap, self::frame('app', self::span('a', 4, 1)));

        $result = $tool->call(['since' => $cursor]);

        Assert::same(self::ids($result), [self::traceId('c'), self::traceId('a')]);
        Assert::same($tool->call(['since' => $result['cursor']])['traces'], []);
    }

    #[DataSet(['0123abcd:1'], 'of another process')]
    #[DataSet(['garbage'], 'no sequence number')]
    #[DataSet([''], 'empty')]
    public function testForeignCursorListsEverything(string $since): void
    {
        $trap = new Trap();
        self::receive($trap, self::frame('app', self::span('a', 1)));

        $result = $trap->get(ListTraces::class)->call(['since' => $since]);

        Assert::same(self::ids($result), [self::traceId('a')]);
    }

    public function testLimitPagesThroughTraces(): void
    {
        $trap = new Trap();
        $store = $trap->get(TelemetryStore::class);
        $tool = $trap->get(ListTraces::class);
        foreach (['a', 'b', 'c'] as $i => $char) {
            self::receive($trap, self::frame('app', self::span($char, $i + 1)));
        }

        $first = $tool->call(['limit' => 2]);
        self::receive($trap, self::frame('app', self::span('a', 4, 1)));
        $second = $tool->call(['limit' => 2, 'since' => $first['cursor']]);

        Assert::same(self::ids($first), [self::traceId('a'), self::traceId('b')]);
        Assert::true($first['has_more']);
        Assert::same($first['cursor'], $store->id . ':2', 'The change of the last listed trace.');
        Assert::same(self::ids($second), [self::traceId('c'), self::traceId('a')], 'The updated trace comes again.');
        Assert::false($second['has_more']);
        Assert::same($second['cursor'], $store->id . ':4');
    }

    public function testErrorsOnly(): void
    {
        $trap = new Trap();
        $store = $trap->get(TelemetryStore::class);
        self::receive($trap, self::frame('app', self::span('a', 1, status: StatusCode::Error)));
        self::receive($trap, self::frame('app', self::span('b', 2, status: StatusCode::Ok)));
        self::receive($trap, self::frame('app', self::span('c', 3, status: StatusCode::Error)));

        $result = $trap->get(ListTraces::class)->call(['errors_only' => true, 'limit' => 1]);

        Assert::same(self::ids($result), [self::traceId('a')]);
        Assert::true($result['has_more']);
        Assert::same($result['cursor'], $store->id . ':1');
    }

    #[DataSet([['service' => 'shop'], ['a', 'c']], 'service')]
    #[DataSet([['service' => ['SHOP', 'd*']], ['a', 'b', 'c']], 'services with a wildcard')]
    #[DataSet([['service' => 'none'], []], 'unknown service')]
    #[DataSet([['name' => 'get /api/*'], ['a', 'b']], 'name wildcard')]
    #[DataSet([['name' => 'GET /api/orders'], ['a']], 'whole name')]
    #[DataSet([['min_duration_ms' => 2], ['a', 'c']], 'minimum duration')]
    #[DataSet([['min_duration_ms' => 2.5], ['c']], 'fractional minimum duration')]
    #[DataSet([['search' => 'SELECT'], ['b']], 'search in attributes')]
    #[DataSet([['search' => 'charge'], ['c']], 'search in child span names')]
    #[DataSet([['search' => 'db\.statement=select', 'regex' => true], ['b']], 'regex over attributes')]
    #[DataSet([['from' => '1h'], ['a', 'b', 'c']], 'updated in the last hour')]
    #[DataSet([['to' => '2001-01-01T00:00:00Z'], []], 'updated long ago')]
    #[DataSet([['service' => 'shop', 'min_duration_ms' => 3, 'search' => 'charge'], ['c']], 'all together')]
    public function testFilters(array $arguments, array $expected): void
    {
        $trap = new Trap();
        $store = $trap->get(TelemetryStore::class);
        self::receive($trap, self::frame('shop', self::span('a', 1, null, 'GET /api/orders', 0, 2_000_000)));
        self::receive($trap, self::frame('db', new Span(
            traceId: self::traceId('b'),
            spanId: \sprintf('%016x', 2),
            parentSpanId: null,
            name: 'GET /api/users',
            kind: SpanKind::Client,
            startTimeUnixNano: self::BASE,
            endTimeUnixNano: self::BASE + 1_000_000,
            attributes: ['db.statement' => 'SELECT * FROM users', 'db.rows' => 3],
        )));
        self::receive($trap, self::frame('shop', self::span('c', 3, null, 'POST /checkout', 0, 1_000_000)));
        self::receive($trap, self::frame('shop', self::span('c', 4, 3, 'charge card', 0, 3_000_000)));

        $result = $trap->get(ListTraces::class)->call($arguments);

        Assert::same(self::ids($result), \array_map(self::traceId(...), $expected));
    }

    #[DataSet(['SQLSTATE[HY000]', ['a']], 'exception message of a span event')]
    #[DataSet(['exception', ['a']], 'span event name')]
    #[DataSet(['card declined', ['b']], 'status message')]
    #[DataSet(['eu-west-1', ['b']], 'resource attribute')]
    #[DataSet(['service.name=shop', ['a']], 'service name of the resource')]
    public function testSearchCoversEventsStatusAndResources(string $search, array $expected): void
    {
        $trap = new Trap();
        $store = $trap->get(TelemetryStore::class);
        self::receive($trap, self::frame('shop', new Span(
            traceId: self::traceId('a'),
            spanId: \sprintf('%016x', 1),
            parentSpanId: null,
            name: 'GET /orders',
            kind: SpanKind::Server,
            startTimeUnixNano: self::BASE,
            endTimeUnixNano: self::BASE + 1,
            events: [new SpanEvent(0, 'exception', ['exception.message' => 'SQLSTATE[HY000] gone away'])],
        )));
        self::receive($trap, new OtlpTraces([new ResourceSpans(
            [new ScopeSpans([self::span('b', 2, status: StatusCode::Error, message: 'card declined')])],
            ['service.name' => 'billing', 'cloud.region' => 'eu-west-1'],
        )]));

        $result = $trap->get(ListTraces::class)->call(['search' => $search]);

        Assert::same(self::ids($result), \array_map(self::traceId(...), $expected));
    }

    #[DataSet([['search' => 'tags=["web","eu"]']], 'list as JSON')]
    #[DataSet([['search' => '(?m)^ratio=$', 'regex' => true]], 'no value for what JSON cannot encode')]
    public function testSearchCoversStructuredAttributes(array $arguments): void
    {
        $trap = new Trap();
        self::receive($trap, self::frame('app', new Span(
            traceId: self::traceId('a'),
            spanId: \sprintf('%016x', 1),
            parentSpanId: null,
            name: 'GET /',
            kind: SpanKind::Server,
            startTimeUnixNano: self::BASE,
            endTimeUnixNano: self::BASE + 1,
            attributes: ['tags' => ['web', 'eu'], 'ratio' => [\NAN]],
        )));

        $result = $trap->get(ListTraces::class)->call($arguments);

        Assert::same(self::ids($result), [self::traceId('a')]);
    }

    public function testListedUpdateTimeTakesItsTraceBack(): void
    {
        $trap = new Trap();
        self::receive($trap, self::frame('app', self::span('a', 1)));
        $tool = $trap->get(ListTraces::class);
        $updated = $tool->call([])['traces'][0]['updated'];

        $result = $tool->call(['from' => $updated, 'to' => $updated]);

        Assert::same(self::ids($result), [self::traceId('a')]);
    }

    #[DataSet([[]], 'some traces')]
    #[DataSet([['errors_only' => true]], 'no traces')]
    #[DataSet([['limit' => 1]], 'more to come')]
    public function testResultConformsToOutputSchema(array $arguments): void
    {
        $trap = new Trap();
        $store = $trap->get(TelemetryStore::class);
        $tool = $trap->get(ListTraces::class);
        self::receive($trap, self::frame(null, self::span('a', 1, 9)));
        self::receive($trap, self::frame('app', self::span('b', 2)));

        $result = $tool->call($arguments);

        Assert::same(JsonSchema::errors($result, $tool->outputSchema()), []);
    }

    public function testListsSentryTracesWithOpenTelemetryOnes(): void
    {
        $trap = new Trap();
        self::receive($trap, self::frame('gateway', self::span('a', 1, null, 'GET /orders', 0, 2_000_000)));
        self::sentry($trap, 'b', 'shop');

        $result = $trap->get(ListTraces::class)->call([]);

        Assert::same(self::ids($result), [self::traceId('a'), self::traceId('b')]);
        Assert::same(\array_diff_key($result['traces'][1], ['updated' => true]), [
            'id' => self::traceId('b'),
            'name' => 'POST /checkout',
            'service' => 'checkout-api',
            'services' => ['checkout-api'],
            'sources' => ['sentry'],
            'projects' => ['shop'],
            'spans' => 3,
            'duration_ms' => 250.0,
            'error' => true,
        ]);
    }

    #[DataSet([['source' => 'sentry'], ['b', 'c']], 'Sentry')]
    #[DataSet([['source' => 'otel'], ['a']], 'OpenTelemetry')]
    #[DataSet([['project' => 'shop'], ['b']], 'project')]
    #[DataSet([['project' => ['', 'billing']], ['a', 'c']], 'projects, the default one by an empty name')]
    #[DataSet([['project' => 'none'], []], 'unknown project')]
    #[DataSet([['service' => 'checkout-*'], ['b', 'c']], 'service of Sentry')]
    #[DataSet([['search' => 'db.query: SELECT'], ['b', 'c']], 'op and description of a Sentry span')]
    #[DataSet([['errors_only' => true, 'project' => 'shop'], ['b']], 'Sentry failure')]
    public function testFiltersSentryAndOpenTelemetryTraces(array $arguments, array $expected): void
    {
        $trap = new Trap();
        self::receive($trap, self::frame('gateway', self::span('a', 1, null, 'GET /orders', 0, 2_000_000)));
        self::sentry($trap, 'b', 'shop');
        self::sentry($trap, 'c', 'billing');

        $result = $trap->get(ListTraces::class)->call($arguments);

        Assert::same(self::ids($result), \array_map(self::traceId(...), $expected));
    }

    public function testProjectScopesTheSummary(): void
    {
        $trap = new Trap();
        self::receive($trap, self::frame('gateway', self::span('a', 1, null, 'GET /orders', 0, 2_000_000)));
        $trap->get(TelemetryMapper::class)->map((new OtlpTraces([new ResourceSpans(
            [new ScopeSpans([self::span('a', 2, 1, 'charge', 0, 5_000_000)])],
            ['service.name' => 'billing'],
        )]))->withProject('billing'));

        $all = $trap->get(ListTraces::class)->call([])['traces'][0];
        $billing = $trap->get(ListTraces::class)->call(['project' => 'billing'])['traces'][0];

        Assert::same([$all['spans'], $all['projects'], $all['services']], [2, ['default', 'billing'], ['gateway', 'billing']]);
        Assert::same(
            [$billing['name'], $billing['spans'], $billing['projects'], $billing['duration_ms']],
            ['charge', 1, ['billing'], 5.0],
        );
    }

    #[DataSet([['since' => 1], '`since` must be a string.'], 'cursor not a string')]
    #[DataSet([['limit' => 101], '`limit` must be an integer from 1 to 100.'], 'limit above maximum')]
    #[DataSet([['errors_only' => 'yes'], '`errors_only` must be a boolean.'], 'flag not a boolean')]
    #[DataSet([['name' => 1], '`name` must be a string.'], 'name not a string')]
    #[DataSet([['service' => [1]], '`service` must be a string or a non-empty list of strings.'], 'service not a string')]
    #[DataSet([['service' => []], '`service` must be a string or a non-empty list of strings.'], 'no services')]
    #[DataSet([['regex' => true], '`regex` needs `search`, the pattern to match.'], 'regex without search')]
    #[DataSet([['min_duration_ms' => -1], '`min_duration_ms` must be a number of at least 0.'], 'negative duration')]
    #[DataSet([['min_duration_ms' => '5'], '`min_duration_ms` must be a number of at least 0.'], 'duration string')]
    #[DataSet([['from' => '5m', 'to' => '1h'], '`from` is later than `to`.'], 'time range')]
    #[DataSet([['source' => 'zipkin'], '`source` must be one of: sentry, otel.'], 'unknown source')]
    #[DataSet([['project' => 1], '`project` must be a string or a non-empty list of strings.'], 'project not a string')]
    #[DataSet([['search' => '[', 'regex' => true], '`search` is not a valid regular expression: Compilation failed: missing terminating ] for character class at offset 1.'], 'regex')]
    public function testRejectsInvalidArguments(array $arguments, string $message): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage($message);

        (new Trap())->get(ListTraces::class)->call($arguments);
    }

    public function testIsReadOnlyToolOfTheTracingModule(): void
    {
        $trap = new Trap();
        $tool = $trap->get(Tools::class)->get('list_traces');

        Assert::instanceOf($tool, ListTraces::class);
        Assert::same($tool->title(), 'List traces');
        Assert::same($tool->annotations(), ['readOnlyHint' => true, 'openWorldHint' => false]);
        Assert::string($tool->description())->contains('get_trace');
        Assert::same(
            \array_keys($tool->inputSchema()['properties']),
            [
                'since', 'limit', 'project', 'source', 'errors_only', 'from', 'to', 'service', 'name',
                'min_duration_ms', 'search', 'regex',
            ],
        );
    }

    /**
     * @return list<string>
     */
    private static function ids(array $result): array
    {
        return \array_column($result['traces'], 'id');
    }

    private static function receive(Trap $trap, OtlpTraces $frame): void
    {
        $trap->get(TelemetryMapper::class)->map($frame);
    }

    /**
     * A Sentry transaction of 250 ms with a query and a failed HTTP call.
     */
    private static function sentry(Trap $trap, string $trace, string $project): void
    {
        $start = (float) (self::BASE / 1_000_000_000);
        $trap->get(EnvelopeMapper::class)->map(new SentryEnvelope([], [new EnvelopeItem(['type' => 'transaction'], [
            'event_id' => "{$trace}1",
            'transaction' => 'POST /checkout',
            'server_name' => 'checkout-api',
            'contexts' => ['trace' => ['trace_id' => self::traceId($trace), 'span_id' => 's0', 'op' => 'http.server']],
            'start_timestamp' => $start,
            'timestamp' => $start + 0.25,
            'spans' => [
                ['span_id' => 's1', 'parent_span_id' => 's0', 'op' => 'db.query', 'description' => 'SELECT 1', 'start_timestamp' => $start, 'timestamp' => $start + 0.1],
                ['span_id' => 's2', 'parent_span_id' => 's0', 'op' => 'http.client', 'status' => 'internal_error', 'start_timestamp' => $start + 0.1, 'timestamp' => $start + 0.2],
            ],
        ])], project: $project));
    }

    private static function frame(?string $service, Span ...$spans): OtlpTraces
    {
        return new OtlpTraces([new ResourceSpans(
            [new ScopeSpans(\array_values($spans))],
            $service === null ? [] : ['service.name' => $service],
        )]);
    }

    private static function span(
        string $trace,
        int $id,
        ?int $parent = null,
        ?string $name = null,
        int $start = 0,
        int $end = 1,
        StatusCode $status = StatusCode::Unset,
        string $message = '',
    ): Span {
        return new Span(
            traceId: self::traceId($trace),
            spanId: \sprintf('%016x', $id),
            parentSpanId: $parent === null ? null : \sprintf('%016x', $parent),
            name: $name ?? "span-$id",
            kind: SpanKind::Internal,
            startTimeUnixNano: self::BASE + $start,
            endTimeUnixNano: self::BASE + $end,
            status: new SpanStatus($status, $message),
        );
    }

    private static function traceId(string $char): string
    {
        return \str_repeat($char, 32);
    }
}
