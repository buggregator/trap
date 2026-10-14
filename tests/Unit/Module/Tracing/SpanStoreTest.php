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
