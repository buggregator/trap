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

    public function testResentSpanCoveringItsOldTimeKeepsCachedBounds(): void
    {
        $store = $this->store();
        $traceId = self::traceId('a');
        $cached = static fn(): mixed => (new \ReflectionProperty(SpanStore::class, 'bounds'))->getValue($store)[$traceId];
        $store->add(self::timedSpan('a', 1, null, 0, 100), self::timedSpan('a', 2, 1, 10, 20));

        $store->add(self::timedSpan('a', 1, null, 0, 120));
        self::assertSame([0, 120], $cached());

        $store->add(self::timedSpan('a', 1, null, 5, 120));
        self::assertNull($cached());
        self::assertSame([5, 120], $store->getTraceBounds($traceId));
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
            self::assertSame(\count($spans), $store->countTraceSpans($traceId), $case);
            if ($spans === []) {
                self::assertNull($store->getTraceBounds($traceId), $case);
                continue;
            }

            $stored[$traceId] = true;
            self::assertSame(
                [
                    \min(\array_map(static fn(Span $span): int => $span->startTimeUnixNano, $spans)),
                    \max(\array_map(static fn(Span $span): int => $span->endTimeUnixNano, $spans)),
                ],
                $store->getTraceBounds($traceId),
                $case,
            );

            $expected = $actual = [];
            foreach ($spans as $span) {
                $span->parentSpanId === null or $expected[$span->parentSpanId][] = $span;
            }
            for ($id = 1; $id <= $maxId; ++$id) {
                $children = $store->getChildren($traceId, $spanId = \sprintf('%016x', $id));
                $children === [] or $actual[$spanId] = $children;
            }
            \ksort($expected);
            self::assertSame($expected, $actual, $case);
        }

        // Nothing is left in the index for dropped traces and spans, and no list stays empty.
        $children = (new \ReflectionProperty(SpanStore::class, 'children'))->getValue($store);
        $bounds = (new \ReflectionProperty(SpanStore::class, 'bounds'))->getValue($store);
        self::assertSame([], \array_diff_key($children, $stored), $case);
        self::assertSame([], \array_diff_key($bounds, $stored), $case);
        foreach ($children as $traceId => $parents) {
            foreach ($parents as $parentId => $list) {
                self::assertNotSame([], $list, $case);
                foreach ($list as $childId => $_) {
                    self::assertSame(
                        (string) $parentId,
                        $store->get((string) $traceId, (string) $childId)?->parentSpanId,
                        $case,
                    );
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
