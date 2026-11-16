<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Tracing;

use Buggregator\Trap\Module\Tracing\Config\Traces;
use Buggregator\Trap\Module\Tracing\Frame\OtlpTraces;
use Buggregator\Trap\Module\Tracing\Frame\Trace\ResourceSpans;
use Buggregator\Trap\Module\Tracing\Frame\Trace\ScopeSpans;
use Buggregator\Trap\Module\Tracing\Frame\Trace\Span;
use Buggregator\Trap\Module\Tracing\Frame\Trace\SpanKind;
use Buggregator\Trap\Module\Tracing\Frame\Trace\SpanStatus;
use Buggregator\Trap\Module\Tracing\Frame\Trace\StatusCode;
use Buggregator\Trap\Module\Tracing\SpanStore;
use Buggregator\Trap\Module\Tracing\TraceSummary;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Covers(SpanStore::class)]
#[Test]
final class SpanStoreTest
{
    private float $now = 1000.0;

    #[BeforeTest]
    public function resetClock(): void
    {
        $this->now = 1000.0;
    }

    public function testFindsSpansByTraceAndSpanId(): void
    {
        $store = $this->store();
        $root = self::span('a', '1');
        $child = self::span('a', '2', '1');
        $other = self::span('b', '3');

        $store->add($child);
        $store->add($root, $other);

        Assert::same($store->get(self::traceId('a'), self::spanId('1')), $root);
        Assert::null($store->get(self::traceId('a'), self::spanId('3')));
        Assert::same($store->getTrace(self::traceId('a')), [self::spanId('2') => $child, self::spanId('1') => $root]);
        Assert::same($store->getTrace(self::traceId('c')), []);
        Assert::same($store->countSpans(), 3);
        Assert::same($store->countTraces(), 2);
    }

    public function testSameSpanIdReplacesStoredSpan(): void
    {
        $store = $this->store();
        $first = self::span('a', '1');
        $second = self::span('a', '1');

        $store->add($first, $second);

        Assert::same($store->get(self::traceId('a'), self::spanId('1')), $second);
        Assert::same($store->countSpans(), 1);
    }

    public function testEvictsLeastRecentlyUpdatedTracesOverCapacity(): void
    {
        $store = $this->store(maxSpans: 3);

        $store->add(self::span('a', '1'), self::span('a', '2'));
        $store->add(self::span('b', '3'));
        // Updating trace `a` makes `b` the least recently updated one.
        $store->add(self::span('a', '4'));

        Assert::same($store->getTrace(self::traceId('b')), []);
        Assert::count($store->getTrace(self::traceId('a')), 3);
        Assert::same($store->countSpans(), 3);
    }

    public function testTrimsOldestSpansOfSingleTraceOverCapacity(): void
    {
        $store = $this->store(maxSpans: 2);

        $store->add(self::span('a', '1'));
        $store->add(self::span('b', '2'), self::span('b', '3'), self::span('b', '4'));

        Assert::same($store->countTraces(), 1);
        Assert::same(self::spanIds($store->getTrace(self::traceId('b'))), [self::spanId('3'), self::spanId('4')]);
        Assert::same($store->countSpans(), 2);
    }

    public function testEndlessTraceStaysWithinCapacity(): void
    {
        $store = $this->store(maxSpans: 50);

        for ($i = 0; $i < 1000; ++$i) {
            $store->add(new Span(
                traceId: self::traceId('a'),
                spanId: \sprintf('%016x', $i + 1),
                parentSpanId: null,
                name: 'span',
                kind: SpanKind::Internal,
                startTimeUnixNano: 0,
                endTimeUnixNano: 1,
            ));
        }

        // Trimming keeps 90% of the limit, then the trace grows back to the limit.
        Assert::numeric($store->countSpans())->greaterThanOrEqual(45);
        Assert::numeric($store->countSpans())->lessThanOrEqual(50);
        Assert::count($store->getTrace(self::traceId('a')), $store->countSpans());
        Assert::notNull($store->get(self::traceId('a'), \sprintf('%016x', 1000)));
        Assert::null($store->get(self::traceId('a'), \sprintf('%016x', 950)));
    }

    public function testReAddedSpanCountsAsRecent(): void
    {
        $store = $this->store(maxSpans: 2);

        $store->add(self::span('a', '1'), self::span('a', '2'));
        $store->add(self::span('a', '1'));
        $store->add(self::span('a', '3'));

        Assert::same(self::spanIds($store->getTrace(self::traceId('a'))), [self::spanId('1'), self::spanId('3')]);
    }

    public function testEvictsExpiredTraces(): void
    {
        $store = $this->store(ttl: 60.0);

        $store->add(self::span('a', '1'));
        $this->now += 30;
        $store->add(self::span('b', '2'));
        $this->now += 31;
        $store->add(self::span('c', '3'));

        Assert::same($store->getTrace(self::traceId('a')), []);
        Assert::count($store->getTrace(self::traceId('b')), 1);
        Assert::same($store->countSpans(), 2);
    }

    public function testInvalidLimitsFallBackToDefaults(): void
    {
        // 10 000 spans and 600 seconds by default.
        $store = $this->store(maxSpans: 0, ttl: -1.0);

        $store->add(self::span('a', '1'), self::span('a', '2'));
        $store->add(self::span('b', '3'));
        $this->now += 599;
        $store->add(self::span('c', '4'));

        Assert::same($store->countTraces(), 3);

        $this->now += 2;
        $store->add(self::span('d', '5'));

        Assert::same($store->getTrace(self::traceId('a')), []);
        Assert::same($store->countTraces(), 2);
    }

    public function testIndexesChildrenAndBounds(): void
    {
        $store = $this->store();
        $store->add(
            self::timedSpan('a', 2, 1, 10, 20),
            self::timedSpan('a', 3, 1, 5, 30),
            self::timedSpan('a', 4, 2, 12, 13),
            self::timedSpan('b', 1, null, 0, 100),
        );

        $children = $store->getChildren(self::traceId('a'), \sprintf('%016x', 1));
        Assert::same(self::spanIds($children), [\sprintf('%016x', 2), \sprintf('%016x', 3)]);
        Assert::same($store->getTraceBounds(self::traceId('a')), [5, 30]);
        Assert::same($store->countTraceSpans(self::traceId('a')), 3);
        Assert::same($store->getChildren(self::traceId('a'), \sprintf('%016x', 4)), []);
        Assert::null($store->getTraceBounds(self::traceId('c')));

        // A replaced span moves to the end of its new siblings, and its old times no longer count.
        $store->add(self::timedSpan('a', 3, 1, 11, 19), self::timedSpan('a', 2, 1, 10, 20));
        Assert::same(self::spanIds($store->getChildren(self::traceId('a'), \sprintf('%016x', 1))), [\sprintf('%016x', 3), \sprintf('%016x', 2)]);
        Assert::same($store->getTraceBounds(self::traceId('a')), [10, 20]);
    }

    public function testResentSpanCoveringItsOldTimeKeepsCachedBounds(): void
    {
        $store = $this->store();
        $traceId = self::traceId('a');
        $cached = static fn(): mixed => (new \ReflectionProperty(SpanStore::class, 'bounds'))->getValue($store)[$traceId];
        $store->add(self::timedSpan('a', 1, null, 0, 100), self::timedSpan('a', 2, 1, 10, 20));

        $store->add(self::timedSpan('a', 1, null, 0, 120));
        Assert::same($cached(), [0, 120]);

        $store->add(self::timedSpan('a', 1, null, 5, 120));
        Assert::null($cached());
        Assert::same($store->getTraceBounds($traceId), [5, 120]);
    }

    /**
     * The index matches a scan of the stored spans after random sequences of batched adds,
     * re-sent spans with another parent or other times, trims, evictions and expirations.
     */
    public function testIndexMatchesStoredSpans(): void
    {
        for ($seed = 1; $seed <= 40; ++$seed) {
            \mt_srand($seed);
            $maxSpans = [1, 2, 3, 10, 25, 60][\mt_rand(0, 5)];
            $traces = \array_slice(['a', 'b', 'c', 'd', 'e'], 0, \mt_rand(1, 5));
            $ids = \mt_rand(3, 40);
            $this->now = 1000.0;
            $store = $this->store($maxSpans, [0.5, 600.0][\mt_rand(0, 1)]);

            for ($step = 0; $step < 100; ++$step) {
                $this->now += \mt_rand(0, 2) * 0.3;
                $batch = [];
                for ($i = \mt_rand(1, 6); $i > 0; --$i) {
                    $id = \mt_rand(1, $ids);
                    $start = \mt_rand(-50, 1000);
                    $batch[] = self::timedSpan(
                        $traces[\mt_rand(0, \count($traces) - 1)],
                        $id,
                        match (\mt_rand(0, 5)) {
                            0 => null,
                            1 => $id,
                            2 => $ids + \mt_rand(1, 3),
                            default => \mt_rand(1, $ids),
                        },
                        $start,
                        $start + \mt_rand(-10, 300),
                    );
                }
                $store->add(...$batch);

                $this->assertIndexMatches($store, $traces, $ids + 3, "seed $seed, step $step");
            }
        }
        \mt_srand();
    }

    public function testEveryStoredSpanTakesNextSequenceNumber(): void
    {
        $store = $this->store();

        $store->add(self::span('a', '1'), self::span('b', '2'), self::span('a', '3'));
        $store->receive(self::frame('app', self::span('c', '4'), self::span('b', '5', '2')));

        Assert::same($store->cursor(), 5);
        Assert::same(
            \array_map(static fn(TraceSummary $trace): int => $trace->seq, \iterator_to_array($store->traces(), false)),
            [3, 4, 5],
        );
    }

    public function testAddedSpansHaveNoService(): void
    {
        $store = $this->store();
        $store->receive(self::frame('app', self::span('a', '1')));

        $store->add(self::span('a', '1'), self::span('b', '2'));

        Assert::null($store->summary(self::traceId('a'))?->service, 'The span sent again replaces its service too.');
        Assert::null($store->summary(self::traceId('b'))?->service);
        Assert::same($store->summary(self::traceId('b'))?->name, 'span-2');
    }

    public function testListsTracesFromLeastRecentlyUpdated(): void
    {
        $store = $this->store();
        $store->add(self::span('a', '1'));
        $store->add(self::span('b', '2'));
        $store->add(self::span('c', '3'));
        $store->add(self::span('a', '4'));

        $all = \iterator_to_array($store->traces(), false);
        $since = \iterator_to_array($store->traces(2), false);

        Assert::same(
            \array_map(static fn(TraceSummary $trace): string => $trace->traceId, $all),
            [self::traceId('b'), self::traceId('c'), self::traceId('a')],
        );
        Assert::same(\array_map(static fn(TraceSummary $trace): int => $trace->seq, $all), [2, 3, 4]);
        Assert::same(
            \array_map(static fn(TraceSummary $trace): string => $trace->traceId, $since),
            [self::traceId('c'), self::traceId('a')],
        );
        Assert::same(\iterator_to_array($store->traces(4), false), []);
    }

    public function testListingDropsExpiredTraces(): void
    {
        $store = $this->store(ttl: 10.0);
        $store->add(self::span('a', '1'));
        $this->now += 5;
        $store->add(self::span('b', '2'));
        $this->now += 6;

        $traces = \iterator_to_array($store->traces(), false);

        Assert::count($traces, 1);
        Assert::same($traces[0]->traceId, self::traceId('b'));
    }

    public function testSummaryOfExpiredTraceIsNull(): void
    {
        $store = $this->store(ttl: 10.0);
        $store->add(self::span('a', '1'));
        $this->now += 11;

        Assert::null($store->summary(self::traceId('a')));
        Assert::same($store->getTrace(self::traceId('a')), []);
    }

    public function testSummarizesTrace(): void
    {
        $store = $this->store();
        $store->receive(self::frame('db', self::timedSpan('a', 2, 1, 20, 30)));
        $store->receive(self::frame('web', self::timedSpan('a', 1, null, 10, 50)));
        $this->now += 2;
        $store->receive(self::frame(null, new Span(
            traceId: self::traceId('a'),
            spanId: \sprintf('%016x', 3),
            parentSpanId: \sprintf('%016x', 1),
            name: 'failed',
            kind: SpanKind::Client,
            startTimeUnixNano: 40,
            endTimeUnixNano: 60,
            status: new SpanStatus(StatusCode::Error),
        )));

        $summary = $store->summary(self::traceId('a'));

        Assert::equals($summary, new TraceSummary(
            traceId: self::traceId('a'),
            name: 'span-1',
            service: 'web',
            spans: 3,
            start: 10,
            end: 60,
            error: true,
            updatedAt: 1002.0,
            seq: 3,
        ));
        Assert::null($store->summary(self::traceId('b')));
    }

    public function testRootWithoutParentWinsOverEarlierOrphan(): void
    {
        $store = $this->store();
        $store->receive(self::frame('worker', self::timedSpan('a', 5, 9, 0, 10)));
        $store->receive(self::frame(null, self::timedSpan('a', 1, null, 5, 10)));

        $summary = $store->summary(self::traceId('a'));

        Assert::same($summary?->name, 'span-1');
        Assert::same($summary?->service, 'worker', 'The root has no service: any span gives it.');
        Assert::false($summary?->error);
    }

    public function testRootOfTraceWithoutRootSpan(): void
    {
        $store = $this->store();
        $store->add(self::timedSpan('a', 3, 9, 30, 40), self::timedSpan('a', 2, 8, 20, 40), self::timedSpan('a', 4, 2, 25, 30));
        $store->add(self::timedSpan('b', 1, 2, 0, 1), self::timedSpan('b', 2, 1, 0, 1), self::timedSpan('b', 7, 7, 0, 1));

        Assert::same($store->summary(self::traceId('a'))?->name, 'span-2', 'The earliest span whose parent is missing.');
        Assert::null($store->summary(self::traceId('b'))?->name, 'A parent cycle and a self parent have no root.');
        Assert::null($store->summary(self::traceId('b'))?->service);
    }

    public function testEvictedTraceLeavesNothing(): void
    {
        $store = $this->store(maxSpans: 2);
        $store->receive(self::frame('app', self::span('a', '1')));
        $store->receive(self::frame('app', self::span('b', '2'), self::span('b', '3')));

        Assert::same(
            \array_map(static fn(TraceSummary $trace): string => $trace->traceId, \iterator_to_array($store->traces(), false)),
            [self::traceId('b')],
        );
        foreach (['services', 'changedAt'] as $property) {
            Assert::same(\array_keys((new \ReflectionProperty(SpanStore::class, $property))->getValue($store)), [self::traceId('b')], $property);
        }
    }

    public function testStoresWithDifferentIds(): void
    {
        Assert::notSame($this->store()->id, $this->store()->id);
    }

    private static function frame(?string $service, Span ...$spans): OtlpTraces
    {
        return new OtlpTraces([new ResourceSpans(
            [new ScopeSpans(\array_values($spans))],
            $service === null ? [] : ['service.name' => $service],
        )]);
    }

    /**
     * @param array<array-key, Span> $spans
     * @return list<string>
     */
    private static function spanIds(array $spans): array
    {
        return \array_values(\array_map(static fn(Span $span): string => $span->spanId, $spans));
    }

    private static function span(string $trace, string $span, ?string $parent = null): Span
    {
        return new Span(
            traceId: self::traceId($trace),
            spanId: self::spanId($span),
            parentSpanId: $parent === null ? null : self::spanId($parent),
            name: "span-$span",
            kind: SpanKind::Internal,
            startTimeUnixNano: 0,
            endTimeUnixNano: 1,
        );
    }

    private static function timedSpan(string $trace, int $span, ?int $parent, int $start, int $end): Span
    {
        return new Span(
            traceId: self::traceId($trace),
            spanId: \sprintf('%016x', $span),
            parentSpanId: $parent === null ? null : \sprintf('%016x', $parent),
            name: "span-$span",
            kind: SpanKind::Internal,
            startTimeUnixNano: $start,
            endTimeUnixNano: $end,
        );
    }

    /**
     * @return non-empty-string
     */
    private static function traceId(string $char): string
    {
        return \str_repeat($char, 32);
    }

    /**
     * @return non-empty-string
     */
    private static function spanId(string $char): string
    {
        return \str_repeat($char, 16);
    }

    /**
     * @param list<string> $traces Trace ID chars.
     * @param int<1, max> $maxId Span IDs to look up children for: 1..$maxId.
     */
    private function assertIndexMatches(SpanStore $store, array $traces, int $maxId, string $case): void
    {
        $stored = [];
        foreach ($traces as $char) {
            $traceId = self::traceId($char);
            $spans = $store->getTrace($traceId);
            Assert::same($store->countTraceSpans($traceId), \count($spans), $case);
            if ($spans === []) {
                Assert::null($store->getTraceBounds($traceId), $case);
                continue;
            }

            $stored[$traceId] = true;
            Assert::same($store->getTraceBounds($traceId), [
                \min(\array_map(static fn(Span $span): int => $span->startTimeUnixNano, $spans)),
                \max(\array_map(static fn(Span $span): int => $span->endTimeUnixNano, $spans)),
            ], $case);

            $expected = $actual = [];
            foreach ($spans as $span) {
                $span->parentSpanId === null or $expected[$span->parentSpanId][] = $span;
            }
            for ($id = 1; $id <= $maxId; ++$id) {
                $children = $store->getChildren($traceId, $spanId = \sprintf('%016x', $id));
                $children === [] or $actual[$spanId] = $children;
            }
            \ksort($expected);
            Assert::same($actual, $expected, $case);
        }

        // Nothing is left in the index for dropped traces and spans, and no list stays empty.
        $children = (new \ReflectionProperty(SpanStore::class, 'children'))->getValue($store);
        $bounds = (new \ReflectionProperty(SpanStore::class, 'bounds'))->getValue($store);
        Assert::same(\array_diff_key($children, $stored), [], $case);
        Assert::same(\array_diff_key($bounds, $stored), [], $case);
        foreach ($children as $traceId => $parents) {
            foreach ($parents as $parentId => $list) {
                Assert::notSame($list, [], $case);
                foreach ($list as $childId => $_) {
                    Assert::same($store->get((string) $traceId, (string) $childId)?->parentSpanId, (string) $parentId, $case);
                }
            }
        }
    }

    private function store(int $maxSpans = 100, float $ttl = 600.0): SpanStore
    {
        $config = new Traces();
        $config->maxSpans = $maxSpans;
        $config->ttl = $ttl;

        return new SpanStore($config, fn(): float => $this->now);
    }
}
