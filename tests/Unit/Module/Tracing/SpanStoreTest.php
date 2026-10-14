<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Tracing;

use Buggregator\Trap\Config\Server\Traces;
use Buggregator\Trap\Module\Tracing\SpanStore;
use Buggregator\Trap\Proto\Frame\Otlp\Trace\Span;
use Buggregator\Trap\Proto\Frame\Otlp\Trace\SpanKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SpanStore::class)]
final class SpanStoreTest extends TestCase
{
    private float $now = 1000.0;

    public function testFindsSpansByTraceAndSpanId(): void
    {
        $store = $this->store();
        $root = self::span('a', '1');
        $child = self::span('a', '2', '1');
        $other = self::span('b', '3');

        $store->add($child);
        $store->add($root, $other);

        self::assertSame($root, $store->get(self::traceId('a'), self::spanId('1')));
        self::assertNull($store->get(self::traceId('a'), self::spanId('3')));
        self::assertSame(
            [self::spanId('2') => $child, self::spanId('1') => $root],
            $store->getTrace(self::traceId('a')),
        );
        self::assertSame([], $store->getTrace(self::traceId('c')));
        self::assertSame(3, $store->countSpans());
        self::assertSame(2, $store->countTraces());
    }

    public function testSameSpanIdReplacesStoredSpan(): void
    {
        $store = $this->store();
        $first = self::span('a', '1');
        $second = self::span('a', '1');

        $store->add($first, $second);

        self::assertSame($second, $store->get(self::traceId('a'), self::spanId('1')));
        self::assertSame(1, $store->countSpans());
    }

    public function testEvictsLeastRecentlyUpdatedTracesOverCapacity(): void
    {
        $store = $this->store(maxSpans: 3);

        $store->add(self::span('a', '1'), self::span('a', '2'));
        $store->add(self::span('b', '3'));
        // Updating trace `a` makes `b` the least recently updated one.
        $store->add(self::span('a', '4'));

        self::assertSame([], $store->getTrace(self::traceId('b')));
        self::assertCount(3, $store->getTrace(self::traceId('a')));
        self::assertSame(3, $store->countSpans());
    }

    public function testTrimsOldestSpansOfSingleTraceOverCapacity(): void
    {
        $store = $this->store(maxSpans: 2);

        $store->add(self::span('a', '1'));
        $store->add(self::span('b', '2'), self::span('b', '3'), self::span('b', '4'));

        self::assertSame(1, $store->countTraces());
        self::assertSame([self::spanId('3'), self::spanId('4')], self::spanIds($store->getTrace(self::traceId('b'))));
        self::assertSame(2, $store->countSpans());
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
        self::assertGreaterThanOrEqual(45, $store->countSpans());
        self::assertLessThanOrEqual(50, $store->countSpans());
        self::assertCount($store->countSpans(), $store->getTrace(self::traceId('a')));
        self::assertNotNull($store->get(self::traceId('a'), \sprintf('%016x', 1000)));
        self::assertNull($store->get(self::traceId('a'), \sprintf('%016x', 950)));
    }

    public function testReAddedSpanCountsAsRecent(): void
    {
        $store = $this->store(maxSpans: 2);

        $store->add(self::span('a', '1'), self::span('a', '2'));
        $store->add(self::span('a', '1'));
        $store->add(self::span('a', '3'));

        self::assertSame([self::spanId('1'), self::spanId('3')], self::spanIds($store->getTrace(self::traceId('a'))));
    }

    public function testEvictsExpiredTraces(): void
    {
        $store = $this->store(ttl: 60.0);

        $store->add(self::span('a', '1'));
        $this->now += 30;
        $store->add(self::span('b', '2'));
        $this->now += 31;
        $store->add(self::span('c', '3'));

        self::assertSame([], $store->getTrace(self::traceId('a')));
        self::assertCount(1, $store->getTrace(self::traceId('b')));
        self::assertSame(2, $store->countSpans());
    }

    public function testInvalidLimitsFallBackToDefaults(): void
    {
        // 10 000 spans and 600 seconds by default.
        $store = $this->store(maxSpans: 0, ttl: -1.0);

        $store->add(self::span('a', '1'), self::span('a', '2'));
        $store->add(self::span('b', '3'));
        $this->now += 599;
        $store->add(self::span('c', '4'));

        self::assertSame(3, $store->countTraces());

        $this->now += 2;
        $store->add(self::span('d', '5'));

        self::assertSame([], $store->getTrace(self::traceId('a')));
        self::assertSame(2, $store->countTraces());
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
        self::assertSame([\sprintf('%016x', 2), \sprintf('%016x', 3)], self::spanIds($children));
        self::assertSame([5, 30], $store->getTraceBounds(self::traceId('a')));
        self::assertSame(3, $store->countTraceSpans(self::traceId('a')));
        self::assertSame([], $store->getChildren(self::traceId('a'), \sprintf('%016x', 4)));
        self::assertNull($store->getTraceBounds(self::traceId('c')));

        // A replaced span moves to the end of its new siblings, and its old times no longer count.
        $store->add(self::timedSpan('a', 3, 1, 11, 19), self::timedSpan('a', 2, 1, 10, 20));
        self::assertSame(
            [\sprintf('%016x', 3), \sprintf('%016x', 2)],
            self::spanIds($store->getChildren(self::traceId('a'), \sprintf('%016x', 1))),
        );
        self::assertSame([10, 20], $store->getTraceBounds(self::traceId('a')));
    }

    /**
     * The index matches a scan of the stored spans after any sequence of adds, replacements,
     * trims and evictions.
     */
    public function testIndexMatchesStoredSpans(): void
    {
        \mt_srand(5);
        $store = $this->store(maxSpans: 60);
        for ($step = 0; $step < 1500; ++$step) {
            $trace = ['a', 'b', 'c', 'd'][\mt_rand(0, 3)];
            $start = \mt_rand(-50, 1000);
            $store->add(self::timedSpan(
                $trace,
                \mt_rand(1, 80),
                \mt_rand(0, 4) === 0 ? null : \mt_rand(1, 85),
                $start,
                $start + \mt_rand(-10, 300),
            ));

            foreach (['a', 'b', 'c', 'd'] as $char) {
                $traceId = self::traceId($char);
                $spans = $store->getTrace($traceId);
                self::assertSame(\count($spans), $store->countTraceSpans($traceId));
                if ($spans === []) {
                    self::assertNull($store->getTraceBounds($traceId));
                    continue;
                }

                self::assertSame(
                    [
                        \min(\array_map(static fn(Span $span): int => $span->startTimeUnixNano, $spans)),
                        \max(\array_map(static fn(Span $span): int => $span->endTimeUnixNano, $spans)),
                    ],
                    $store->getTraceBounds($traceId),
                );
                $expected = $actual = [];
                foreach ($spans as $span) {
                    $span->parentSpanId === null or $expected[$span->parentSpanId][] = $span;
                }
                for ($id = 1; $id <= 85; ++$id) {
                    $children = $store->getChildren($traceId, $spanId = \sprintf('%016x', $id));
                    $children === [] or $actual[$spanId] = $children;
                }
                \ksort($expected);
                self::assertSame($expected, $actual);
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

    private function store(int $maxSpans = 100, float $ttl = 600.0): SpanStore
    {
        $config = new Traces();
        $config->maxSpans = $maxSpans;
        $config->ttl = $ttl;

        return new SpanStore($config, fn(): float => $this->now);
    }
}
