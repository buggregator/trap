<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Tracing;

use Buggregator\Trap\Config\Server\Traces;
use Buggregator\Trap\Module\Tracing\SpanStore;
use Buggregator\Trap\Proto\Frame\Otlp\Trace\Span;
use Buggregator\Trap\Proto\Frame\Otlp\Trace\SpanKind;
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
