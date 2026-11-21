<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Tracing\Frontend;

use Buggregator\Trap\Module\Tracing\Frontend\TelemetryMapper;
use Buggregator\Trap\Module\Tracing\Http\OtlpTrap\JsonLogsDecoder;
use Buggregator\Trap\Module\Tracing\Http\OtlpTrap\JsonMetricsDecoder;
use Buggregator\Trap\Module\Tracing\Http\OtlpTrap\JsonTracesDecoder;
use Buggregator\Trap\Server\Telemetry\Source;
use Buggregator\Trap\Server\Telemetry\TelemetryStore;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Covers(TelemetryMapper::class)]
#[Test]
final class TelemetryMapperTest
{
    private const TRACE = '5b8efff798038103d269b633813fc60c';

    public function testEntrySpansBecomeTransactionsAndTheRestSpans(): void
    {
        $store = new TelemetryStore();
        $frame = (new JsonTracesDecoder())->decode(<<<'JSON'
            {"resourceSpans":[{"resource":{"attributes":[{"key":"service.name","value":{"stringValue":"billing"}},
            {"key":"service.version","value":{"stringValue":"1.2"}},
            {"key":"deployment.environment.name","value":{"stringValue":"dev"}}]},
            "scopeSpans":[{"scope":{"name":"http","version":"1.0"},"spans":[
            {"traceId":"5b8efff798038103d269b633813fc60c","spanId":"1111111111111111","name":"GET /","kind":2,
             "startTimeUnixNano":"1000000000","endTimeUnixNano":"1500000000",
             "attributes":[{"key":"http.request.method","value":{"stringValue":"GET"}}],
             "status":{"code":2,"message":"boom"},
             "events":[{"name":"exception","timeUnixNano":"1200000000"}],
             "links":[{"traceId":"0102030405060708090a0b0c0d0e0f10","spanId":"0102030405060708"}]},
            {"traceId":"5b8efff798038103d269b633813fc60c","spanId":"2222222222222222","parentSpanId":"1111111111111111",
             "name":"SELECT","kind":3,"startTimeUnixNano":"1100000000","endTimeUnixNano":"1200000000",
             "attributes":[{"key":"db.system","value":{"stringValue":"mysql"}},{"key":"server.address","value":{"stringValue":"db"}}]},
            {"traceId":"5b8efff798038103d269b633813fc60c","spanId":"3333333333333333","parentSpanId":"9999999999999999",
             "name":"consume","kind":5,"startTimeUnixNano":"1","endTimeUnixNano":"2",
             "attributes":[{"key":"messaging.system","value":{"stringValue":"kafka"}}]},
            {"traceId":"5b8efff798038103d269b633813fc60c","spanId":"4444444444444444","parentSpanId":"8888888888888888",
             "name":"remote child","kind":1,"flags":768}
            ]}]}]}
            JSON, new \DateTimeImmutable())->withProject('shop');

        Assert::null((new TelemetryMapper($store))->map($frame));

        $trace = $store->trace(self::TRACE);
        Assert::notBlank($trace);
        Assert::same(\array_keys($trace->transactions), [
            self::TRACE . '-1111111111111111',
            self::TRACE . '-3333333333333333',
            self::TRACE . '-4444444444444444',
        ]);
        $root = $trace->transactions[self::TRACE . '-1111111111111111'];
        Assert::same([$root->project, $root->name, $root->op, $root->status, $root->service], ['shop', 'GET /', 'http.server', 'internal_error', 'billing']);
        Assert::same([$root->release, $root->environment, $root->source], ['1.2', 'dev', Source::Otel]);
        Assert::same($root->durationMs(), 500);
        Assert::same($root->attributes['otel.kind'], 'server');
        Assert::same($root->attributes['otel.status_code'], 'error');
        Assert::same($root->attributes['otel.status_description'], 'boom');
        Assert::same($root->attributes['otel.events'], [['name' => 'exception', 'time' => '1970-01-01T00:00:01.200000Z']]);
        Assert::same($root->attributes['otel.links'], [['trace_id' => '0102030405060708090a0b0c0d0e0f10', 'span_id' => '0102030405060708']]);
        Assert::same($root->attributes['otel.scope'], ['name' => 'http', 'version' => '1.0']);
        Assert::same($root->attributes['otel.resource']['service.name'], 'billing');
        Assert::same($trace->transactions[self::TRACE . '-3333333333333333']->op, 'queue.process');

        // An all-digit span ID is an int key of the array.
        Assert::same(\array_map('strval', \array_keys($trace->spans)), ['2222222222222222']);
        $span = $trace->spans['2222222222222222'];
        Assert::same([$span->op, $span->peer->type, $span->peer->address, $span->status], ['db', 'db', 'db', 'ok']);
        Assert::same($span->attributes['otel.status_code'], 'unset');
    }

    public function testUnsetSpanTimesAreUnknown(): void
    {
        $store = new TelemetryStore();
        $frame = (new JsonTracesDecoder())->decode(
            '{"resourceSpans":[{"scopeSpans":[{"spans":[{"traceId":"5b8efff798038103d269b633813fc60c",'
            . '"spanId":"1111111111111111","name":"x"}]}]}]}',
            new \DateTimeImmutable(),
        );

        (new TelemetryMapper($store))->map($frame);

        $transaction = $store->trace(self::TRACE)?->transactions[self::TRACE . '-1111111111111111'];
        Assert::null($transaction?->start);
        Assert::null($transaction?->end);
        Assert::null($transaction?->durationMs());
    }

    #[DataSet([9, '', 'info'])]
    #[DataSet([0, 'WARNING', 'warn'])]
    #[DataSet([0, 'Critical', 'fatal'])]
    #[DataSet([0, '', 'info'])]
    #[DataSet([23, '', 'fatal'])]
    #[DataSet([2, '', 'trace'])]
    public function testLogLevelFromSeverity(int $number, string $text, string $level): void
    {
        $store = new TelemetryStore();
        $json = \json_encode(['resourceLogs' => [['scopeLogs' => [['logRecords' => [
            ['severityNumber' => $number, 'severityText' => $text, 'body' => ['stringValue' => 'x']],
        ]]]]]]);

        (new TelemetryMapper($store))->map((new JsonLogsDecoder())->decode($json, new \DateTimeImmutable()));

        Assert::same($store->logs()[0]->level, $level);
    }

    public function testLogsKeepResourceAndScope(): void
    {
        $store = new TelemetryStore();
        $frame = (new JsonLogsDecoder())->decode(<<<'JSON'
            {"resourceLogs":[{"resource":{"attributes":[{"key":"service.name","value":{"stringValue":"billing"}}]},
            "scopeLogs":[{"scope":{"name":"monolog"},"logRecords":[
            {"observedTimeUnixNano":"2000000000","severityNumber":17,"body":{"arrayValue":{"values":[{"intValue":"1"}]}},
             "traceId":"5b8efff798038103d269b633813fc60c"}]}]}]}
            JSON, new \DateTimeImmutable());

        (new TelemetryMapper($store))->map($frame);

        $log = $store->logs()[0];
        Assert::same([$log->project, $log->level, $log->body, $log->time], ['default', 'error', '[1]', 2.0]);
        Assert::same([$log->service, $log->traceId, $log->source], ['billing', self::TRACE, Source::Otel]);
        Assert::same($log->attributes, ['otel.resource' => ['service.name' => 'billing'], 'otel.scope' => ['name' => 'monolog']]);
    }

    public function testMetricTypes(): void
    {
        $store = new TelemetryStore();
        $frame = (new JsonMetricsDecoder())->decode(<<<'JSON'
            {"resourceMetrics":[{"scopeMetrics":[{"metrics":[
            {"name":"total","sum":{"isMonotonic":true,"dataPoints":[{"asInt":"3","timeUnixNano":"1000000000"}]}},
            {"name":"inflight","sum":{"dataPoints":[{"asInt":"2"}]}},
            {"name":"temp","gauge":{"dataPoints":[{"asDouble":20.5}]}},
            {"name":"latency","summary":{"dataPoints":[{"count":"2","sum":30}]}},
            {"name":"","gauge":{"dataPoints":[{"asDouble":1}]}}
            ]}]}]}
            JSON, new \DateTimeImmutable());

        (new TelemetryMapper($store))->map($frame);

        Assert::same(
            \array_map(static fn($m): array => [$m->name, $m->type, $m->value], $store->metrics()),
            [['total', 'counter', 3.0], ['inflight', 'gauge', 2.0], ['temp', 'gauge', 20.5], ['latency', 'distribution', 15.0]],
        );
        Assert::same($store->metrics()[0]->time, 1.0);
        Assert::null($store->metrics()[1]->time);
        Assert::same($store->metrics()[3]->attributes, ['otel.count' => 2, 'otel.sum' => 30.0]);
    }
}
