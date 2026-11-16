<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Tracing\Mcp;

use Buggregator\Trap\Module\Mcp\Tools;
use Buggregator\Trap\Module\Tracing\Frame\OtlpTraces;
use Buggregator\Trap\Module\Tracing\Frame\Trace\ResourceSpans;
use Buggregator\Trap\Module\Tracing\Frame\Trace\ScopeSpans;
use Buggregator\Trap\Module\Tracing\Frame\Trace\Span;
use Buggregator\Trap\Module\Tracing\Frame\Trace\SpanKind;
use Buggregator\Trap\Module\Tracing\Frame\Trace\SpanStatus;
use Buggregator\Trap\Module\Tracing\Frame\Trace\StatusCode;
use Buggregator\Trap\Module\Tracing\Mcp\ListTraces;
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
        Assert::same(\array_keys($tool->inputSchema()['properties']), ['since', 'limit', 'errors_only']);
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
    ): Span {
        return new Span(
            traceId: self::traceId($trace),
            spanId: \sprintf('%016x', $id),
            parentSpanId: $parent === null ? null : \sprintf('%016x', $parent),
            name: $name ?? "span-$id",
            kind: SpanKind::Internal,
            startTimeUnixNano: $start,
            endTimeUnixNano: $end,
            status: new SpanStatus($status),
        );
    }

    private static function traceId(string $char): string
    {
        return \str_repeat($char, 32);
    }
}
