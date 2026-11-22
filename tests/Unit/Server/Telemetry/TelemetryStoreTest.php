<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Telemetry;

use Buggregator\Trap\Server\Config\Telemetry as Config;
use Buggregator\Trap\Server\Telemetry\Batch;
use Buggregator\Trap\Server\Telemetry\Log;
use Buggregator\Trap\Server\Telemetry\Metric;
use Buggregator\Trap\Server\Telemetry\Source;
use Buggregator\Trap\Server\Telemetry\Span;
use Buggregator\Trap\Server\Telemetry\TelemetryListener;
use Buggregator\Trap\Server\Telemetry\TelemetryStore;
use Buggregator\Trap\Server\Telemetry\Transaction;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(TelemetryStore::class)]
#[Test]
final class TelemetryStoreTest
{
    public function testStoresTransactionsAndSpansByTrace(): void
    {
        $store = new TelemetryStore();

        $store->add(self::batch('default', transactions: [self::transaction('t1', 'a')], spans: [self::span('t1', 's1')]));

        $trace = $store->trace('t1');
        Assert::notBlank($trace);
        Assert::same(\array_keys($trace->transactions), ['a']);
        Assert::same(\array_keys($trace->spans), ['s1']);
        Assert::null($store->trace('t2'));
    }

    public function testSpanWithStoredIdReplacesIt(): void
    {
        $store = new TelemetryStore(self::config(maxSpans: 2));

        $store->add(self::batch('default', spans: [self::span('t1', 's1'), self::span('t1', 's1', 'again')]));
        $store->add(self::batch('default', spans: [self::span('t2', 's2')]));

        Assert::same($store->trace('t1')?->spans['s1']->description, 'again');
        Assert::notBlank($store->trace('t2'));
    }

    public function testDropsLeastRecentlyUpdatedTracesAboveTheLimit(): void
    {
        $store = new TelemetryStore(self::config(maxSpans: 2));

        $store->add(self::batch('default', spans: [self::span('t1', 's1')]));
        $store->add(self::batch('default', spans: [self::span('t2', 's2')]));
        $store->add(self::batch('default', spans: [self::span('t1', 's3')]));
        $store->add(self::batch('default', spans: [self::span('t3', 's4')]));

        Assert::same(\array_keys($store->traces()), ['t3']);
    }

    public function testSingleTraceAboveTheLimitKeepsItsNewestSpans(): void
    {
        $store = new TelemetryStore(self::config(maxSpans: 10));
        $spans = \array_map(static fn(int $i): Span => self::span('t1', "s$i"), \range(1, 12));

        $store->add(self::batch('default', spans: $spans));

        Assert::same(\array_keys($store->trace('t1')?->spans ?? []), ['s4', 's5', 's6', 's7', 's8', 's9', 's10', 's11', 's12']);
    }

    public function testDropsOldestLogsAndMetricsAboveTheLimits(): void
    {
        $store = new TelemetryStore(self::config(maxLogs: 10, maxMetrics: 1));

        $store->add(self::batch('default', logs: \array_map(self::log(...), \range(1, 11))));
        $store->add(self::batch('default', metrics: [self::metric('m1'), self::metric('m2')]));

        Assert::same(\array_map(static fn(Log $log): string => $log->body, $store->logs()), \array_map('strval', \range(3, 11)));
        Assert::same(\array_map(static fn(Metric $metric): string => $metric->name, $store->metrics()), ['m2']);
    }

    public function testInvalidLimitsFallBackToDefaults(): void
    {
        $store = new TelemetryStore(self::config(maxSpans: 0, maxLogs: -1, maxMetrics: 0));

        $store->add(self::batch('default', logs: \array_map(self::log(...), \range(1, 100))));

        Assert::count($store->logs(), 100);
    }

    public function testClearsOneProject(): void
    {
        $store = new TelemetryStore();
        $store->add(self::batch('a', transactions: [self::transaction('t1', 'x', 'a')], logs: [self::log(1, 'a')], metrics: [self::metric('m', 'a')]));
        $store->add(self::batch('b', spans: [self::span('t1', 's', 'b', 'b')], logs: [self::log(2, 'b')]));
        $store->add(self::batch('a', spans: [self::span('t2', 's2', '', 'a')]));

        $store->clear('a');

        Assert::same(\array_keys($store->traces()), ['t1']);
        Assert::same($store->trace('t1')?->transactions, []);
        Assert::same(\array_keys($store->trace('t1')?->spans ?? []), ['s']);
        Assert::count($store->logs(), 1);
        Assert::same($store->metrics(), []);
    }

    public function testClearsEverything(): void
    {
        $store = new TelemetryStore();
        $store->add(self::batch('a', spans: [self::span('t1', 's')], logs: [self::log(1)]));

        $store->clear();

        Assert::same($store->traces(), []);
        Assert::same($store->logs(), []);
    }

    public function testTellsListenersTheStoredKinds(): void
    {
        $store = new TelemetryStore();
        $listener = new class implements TelemetryListener {
            public array $calls = [];

            public function stored(string $project, array $kinds): void
            {
                $this->calls[] = [$project, $kinds];
            }
        };
        $store->listen($listener);

        $store->add(self::batch('shop', spans: [self::span('t1', 's')], metrics: [self::metric('m')]));
        $store->add(self::batch('shop'));
        $store->add(self::batch('default', logs: [self::log(1)]));

        Assert::same($listener->calls, [['shop', ['trace', 'metric']], ['default', ['log']]]);
    }

    public function testEveryChangeOfATraceTakesTheNextSequenceNumber(): void
    {
        $now = 100.0;
        $store = new TelemetryStore(clock: static function () use (&$now): float {
            return $now;
        });

        $store->add(self::batch('default', transactions: [self::transaction('t1', 'a')], spans: [self::span('t2', 's1')]));
        $now = 105.0;
        $store->add(self::batch('default', spans: [self::span('t1', 's2')], logs: [self::log(1)]));
        $store->add(self::batch('default', logs: [self::log(2)]));

        Assert::same($store->cursor(), 3);
        Assert::same(\array_keys($store->traces()), ['t2', 't1']);
        Assert::same($store->trace('t2')?->seq, 2);
        Assert::same($store->trace('t2')?->updatedAt, 100.0);
        Assert::same($store->trace('t1')?->seq, 3);
        Assert::same($store->trace('t1')?->updatedAt, 105.0);
    }

    public function testStoresHaveDifferentIds(): void
    {
        Assert::notSame((new TelemetryStore())->id, (new TelemetryStore())->id);
    }

    public function testDurationsAreBoundedToAYear(): void
    {
        Assert::same(Span::durationBetween(-9e15, 9e15), Span::MAX_DURATION_MS);
        Assert::same(Span::durationBetween(2.0, 1.0), 0);
        Assert::same(Span::durationBetween(1.0, 1.25), 250);
        Assert::null(Span::durationBetween(null, 1.0));
    }

    private static function config(int $maxSpans = 10_000, int $maxLogs = 5_000, int $maxMetrics = 5_000): Config
    {
        $config = new Config();
        $config->maxSpans = $maxSpans;
        $config->maxLogs = $maxLogs;
        $config->maxMetrics = $maxMetrics;

        return $config;
    }

    /**
     * @param non-empty-string $project
     */
    private static function batch(
        string $project,
        array $transactions = [],
        array $spans = [],
        array $logs = [],
        array $metrics = [],
    ): Batch {
        $batch = new Batch($project);
        $batch->transactions = $transactions;
        $batch->spans = $spans;
        $batch->logs = $logs;
        $batch->metrics = $metrics;

        return $batch;
    }

    private static function transaction(string $traceId, string $id, string $project = 'default'): Transaction
    {
        return new Transaction($id, $project, $traceId, null, null, 'GET /', 'http.server', 'ok', 1.0, 2.0, Source::Sentry, null);
    }

    private static function span(string $traceId, string $spanId, string $description = '', string $project = 'default'): Span
    {
        return new Span($project, $traceId, $spanId, null, null, 'db', $description, 'ok', 1.0, 2.0, Source::Otel, null);
    }

    private static function log(int $i, string $project = 'default'): Log
    {
        return new Log("l$i", $project, 'info', (string) $i, (float) $i, Source::Sentry);
    }

    private static function metric(string $name, string $project = 'default'): Metric
    {
        return new Metric("id-$name", $project, $name, 'counter', 1.0, 1.0, Source::Sentry);
    }
}
