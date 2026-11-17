<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Tracing\Mcp;

use Buggregator\Trap\Module\Mcp\Tools;
use Buggregator\Trap\Module\Tracing\Frame\OtlpTraces;
use Buggregator\Trap\Module\Tracing\Frame\Trace\ResourceSpans;
use Buggregator\Trap\Module\Tracing\Frame\Trace\ScopeSpans;
use Buggregator\Trap\Module\Tracing\Frame\Trace\Span;
use Buggregator\Trap\Module\Tracing\Frame\Trace\SpanEvent;
use Buggregator\Trap\Module\Tracing\Frame\Trace\SpanKind;
use Buggregator\Trap\Module\Tracing\Frame\Trace\SpanStatus;
use Buggregator\Trap\Module\Tracing\Frame\Trace\StatusCode;
use Buggregator\Trap\Module\Tracing\Mcp\ListTraces;
use Buggregator\Trap\Module\Tracing\Mcp\TraceFilter;
use Buggregator\Trap\Module\Tracing\SpanStore;
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
final class ListTracesTest
{
    public function testListsLeastRecentlyUpdatedFirst(): void
    {
        $trap = new Trap();
        $store = $trap->get(SpanStore::class);
        $store->receive(self::frame('shop', self::span('a', 1, null, 'GET /cart', 0, 2_500_000)));
        $store->receive(self::frame('shop', self::span('b', 2, null, 'GET /orders', 0, 1)));
        $store->receive(self::frame('db', self::span('a', 3, 1, 'query', 1_000_000, 4_000_000, StatusCode::Error)));

        $result = $trap->get(ListTraces::class)->call([]);

        Assert::same(self::ids($result), [self::traceId('b'), self::traceId('a')]);
        Assert::same(\array_diff_key($result['traces'][1], ['updated' => true]), [
            'id' => self::traceId('a'),
            'name' => 'GET /cart',
            'service' => 'shop',
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
        $store = $trap->get(SpanStore::class);
        $tool = $trap->get(ListTraces::class);
        $store->receive(self::frame('app', self::span('a', 1)));
        $store->receive(self::frame('app', self::span('b', 2)));
        $cursor = $tool->call([])['cursor'];
        $store->receive(self::frame('app', self::span('c', 3)));
        $store->receive(self::frame('app', self::span('a', 4, 1)));

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
        $trap->get(SpanStore::class)->receive(self::frame('app', self::span('a', 1)));

        $result = $trap->get(ListTraces::class)->call(['since' => $since]);

        Assert::same(self::ids($result), [self::traceId('a')]);
    }

    public function testLimitPagesThroughTraces(): void
    {
        $trap = new Trap();
        $store = $trap->get(SpanStore::class);
        $tool = $trap->get(ListTraces::class);
        foreach (['a', 'b', 'c'] as $i => $char) {
            $store->receive(self::frame('app', self::span($char, $i + 1)));
        }

        $first = $tool->call(['limit' => 2]);
        $store->receive(self::frame('app', self::span('a', 4, 1)));
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
        $store = $trap->get(SpanStore::class);
        $store->receive(self::frame('app', self::span('a', 1, status: StatusCode::Error)));
        $store->receive(self::frame('app', self::span('b', 2, status: StatusCode::Ok)));
        $store->receive(self::frame('app', self::span('c', 3, status: StatusCode::Error)));

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
        $store = $trap->get(SpanStore::class);
        $store->receive(self::frame('shop', self::span('a', 1, null, 'GET /api/orders', 0, 2_000_000)));
        $store->receive(self::frame('db', new Span(
            traceId: self::traceId('b'),
            spanId: \sprintf('%016x', 2),
            parentSpanId: null,
            name: 'GET /api/users',
            kind: SpanKind::Client,
            startTimeUnixNano: 0,
            endTimeUnixNano: 1_000_000,
            attributes: ['db.statement' => 'SELECT * FROM users', 'db.rows' => 3],
        )));
        $store->receive(self::frame('shop', self::span('c', 3, null, 'POST /checkout', 0, 1_000_000)));
        $store->receive(self::frame('shop', self::span('c', 4, 3, 'charge card', 0, 3_000_000)));

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
        $store = $trap->get(SpanStore::class);
        $store->receive(self::frame('shop', new Span(
            traceId: self::traceId('a'),
            spanId: \sprintf('%016x', 1),
            parentSpanId: null,
            name: 'GET /orders',
            kind: SpanKind::Server,
            startTimeUnixNano: 0,
            endTimeUnixNano: 1,
            events: [new SpanEvent(0, 'exception', ['exception.message' => 'SQLSTATE[HY000] gone away'])],
        )));
        $store->receive(new OtlpTraces([new ResourceSpans(
            [new ScopeSpans([self::span('b', 2, status: StatusCode::Error, message: 'card declined')])],
            ['service.name' => 'billing', 'cloud.region' => 'eu-west-1'],
        )]));

        $result = $trap->get(ListTraces::class)->call(['search' => $search]);

        Assert::same(self::ids($result), \array_map(self::traceId(...), $expected));
    }

    public function testListedUpdateTimeTakesItsTraceBack(): void
    {
        $trap = new Trap();
        $trap->get(SpanStore::class)->receive(self::frame('app', self::span('a', 1)));
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
        $store = $trap->get(SpanStore::class);
        $tool = $trap->get(ListTraces::class);
        $store->receive(self::frame(null, self::span('a', 1, 9)));
        $store->receive(self::frame('app', self::span('b', 2)));

        $result = $tool->call($arguments);

        Assert::same(JsonSchema::errors($result, $tool->outputSchema()), []);
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
        Assert::same($tool->annotations(), ['readOnlyHint' => true, 'openWorldHint' => false]);
        Assert::string($tool->description())->contains('get_trace');
        Assert::same(
            \array_keys($tool->inputSchema()['properties']),
            ['since', 'limit', 'errors_only', 'from', 'to', 'service', 'name', 'min_duration_ms', 'search', 'regex'],
        );
    }

    /**
     * @return list<string>
     */
    private static function ids(array $result): array
    {
        return \array_column($result['traces'], 'id');
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
            startTimeUnixNano: $start,
            endTimeUnixNano: $end,
            status: new SpanStatus($status, $message),
        );
    }

    private static function traceId(string $char): string
    {
        return \str_repeat($char, 32);
    }
}
