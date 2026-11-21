<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Tracing\Http\OtlpTrap;

use Buggregator\Trap\Module\Tracing\Frame;
use Buggregator\Trap\Module\Tracing\Frame\OtlpLogs;
use Buggregator\Trap\Module\Tracing\Frame\OtlpMetrics;
use Buggregator\Trap\Module\Tracing\Http\OtlpTrap\JsonLogsDecoder;
use Buggregator\Trap\Module\Tracing\Http\OtlpTrap\JsonMetricsDecoder;
use Buggregator\Trap\Module\Tracing\Http\OtlpTrap\JsonReader;
use Buggregator\Trap\Module\Tracing\Http\OtlpTrap\LimitExceededException;
use Buggregator\Trap\Module\Tracing\Http\OtlpTrap\ProtobufDecoder;
use Google\Protobuf\Internal\Message;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Core\Exception\SkipTest;
use Testo\Expect;
use Testo\Test;

#[Covers(JsonLogsDecoder::class)]
#[Covers(JsonMetricsDecoder::class)]
#[Covers(ProtobufDecoder::class)]
#[Covers(JsonReader::class)]
#[Covers(OtlpLogs::class)]
#[Covers(OtlpMetrics::class)]
#[Test]
final class SignalDecodersTest
{
    private const LOGS = <<<'JSON'
        {"resourceLogs":[{"resource":{"attributes":[{"key":"service.name","value":{"stringValue":"billing"}}]},
        "scopeLogs":[{"scope":{"name":"monolog","version":"3"},"logRecords":[
        {"timeUnixNano":"1795000000950000000","severityNumber":"SEVERITY_NUMBER_WARN2","severityText":"WARNING",
         "body":{"kvlistValue":{"values":[{"key":"a","value":{"intValue":"1"}}]}},
         "traceId":"5B8EFFF798038103D269B633813FC60C","spanId":"4444444444444444",
         "attributes":[{"key":"card","value":{"stringValue":"visa"}}]},
        {"observed_time_unix_nano":5,"severity_number":17,"body":{"stringValue":"plain"}}
        ]}]}]}
        JSON;
    private const METRICS = <<<'JSON'
        {"resourceMetrics":[{"resource":{"attributes":[]},"scopeMetrics":[{"metrics":[
        {"name":"payments","unit":"1","sum":{"isMonotonic":true,"dataPoints":[{"timeUnixNano":"10","asInt":"3"}]}},
        {"name":"queue","gauge":{"dataPoints":[{"asDouble":2.5,"attributes":[{"key":"q","value":{"stringValue":"a"}}]}]}},
        {"name":"latency","unit":"ms","histogram":{"dataPoints":[{"count":"4","sum":200,"min":10,"max":120,
         "exemplars":[{"traceId":"5b8efff798038103d269b633813fc60c","spanId":"4444444444444444","asDouble":120}]}]}},
        {"name":"empty"}
        ]}]}]}
        JSON;

    public function testDecodesJsonLogs(): void
    {
        $frame = (new JsonLogsDecoder())->decode(self::LOGS, new \DateTimeImmutable());

        Assert::same($frame->countRecords(), 2);
        $resource = $frame->resourceLogs[0];
        Assert::same($resource['resource']['attributes'], ['service.name' => 'billing']);
        Assert::same($resource['scope'], ['name' => 'monolog', 'version' => '3']);
        [$first, $second] = $resource['logRecords'];
        Assert::same($first['severityNumber'], 14);
        Assert::same($first['severityText'], 'WARNING');
        Assert::same($first['traceId'], '5b8efff798038103d269b633813fc60c');
        Assert::same($first['spanId'], '4444444444444444');
        Assert::same($first['timeUnixNano'], 1795000000950000000);
        Assert::instanceOf($first['body'], \ArrayObject::class);
        Assert::same($second['observedTimeUnixNano'], 5);
        Assert::same($second['severityNumber'], 17);
        Assert::same($second['body'], 'plain');
        Assert::null($second['traceId']);
    }

    public function testDecodesJsonMetricsToOneValuePerPoint(): void
    {
        $frame = (new JsonMetricsDecoder())->decode(self::METRICS, new \DateTimeImmutable());

        Assert::same($frame->countDataPoints(), 3);
        [$sum, $gauge, $histogram] = $frame->resourceMetrics[0]['metrics'];
        Assert::count($frame->resourceMetrics[0]['metrics'], 3);
        Assert::same([$sum['type'], $sum['monotonic'], $sum['dataPoints'][0]['value']], ['sum', true, 3.0]);
        Assert::same([$gauge['type'], $gauge['monotonic'], $gauge['dataPoints'][0]['value']], ['gauge', false, 2.5]);
        Assert::same($gauge['dataPoints'][0]['attributes'], ['q' => 'a']);
        $point = $histogram['dataPoints'][0];
        Assert::same([$point['value'], $point['count'], $point['sum'], $point['min'], $point['max']], [50.0, 4, 200.0, 10.0, 120.0]);
        Assert::same([$point['traceId'], $point['spanId']], ['5b8efff798038103d269b633813fc60c', '4444444444444444']);
    }

    public function testRejectsTooManyLogRecords(): never
    {
        Expect::exception(LimitExceededException::class)->withMessage('Too many log records in one request, the limit is 1.');

        (new JsonLogsDecoder(1))->decode(self::LOGS, new \DateTimeImmutable());
    }

    public function testRejectsTooManyDataPoints(): never
    {
        Expect::exception(LimitExceededException::class)->withMessageContaining('data points');

        (new JsonMetricsDecoder(2))->decode(self::METRICS, new \DateTimeImmutable());
    }

    public function testRejectsTooManyJsonContainersBeforeDecoding(): never
    {
        $payload = '{"x":[' . \rtrim(\str_repeat('[0],', JsonReader::MAX_CONTAINERS), ',') . ']}';
        Expect::exception(LimitExceededException::class)->withMessageContaining('Too many JSON objects and arrays');

        (new JsonLogsDecoder())->decode($payload, new \DateTimeImmutable());
    }

    public function testJsonContainersInStringsDoNotCount(): void
    {
        Assert::same(JsonReader::countContainers('{"a":"[{\"[","b":[{}]}'), 3);
    }

    public function testRejectsInvalidTraceId(): never
    {
        Expect::exception(\InvalidArgumentException::class);

        (new JsonLogsDecoder())->decode(
            '{"resourceLogs":[{"scopeLogs":[{"logRecords":[{"traceId":"abc"}]}]}]}',
            new \DateTimeImmutable(),
        );
    }

    public function testFramesSurviveSerialization(): void
    {
        $time = new \DateTimeImmutable('2026-01-01 00:00:00');
        $logs = (new JsonLogsDecoder())->decode(self::LOGS, $time)->withProject('shop');
        $metrics = (new JsonMetricsDecoder())->decode(self::METRICS, $time);

        $logsCopy = Frame::fromString((string) $logs, $time);
        $metricsCopy = Frame::fromString((string) $metrics, $time);

        Assert::instanceOf($logsCopy, OtlpLogs::class);
        Assert::same($logsCopy->project, 'shop');
        Assert::same((string) $logsCopy, (string) $logs);
        Assert::instanceOf($metricsCopy, OtlpMetrics::class);
        Assert::null($metricsCopy->project);
        Assert::same((string) $metricsCopy, (string) $metrics);
    }

    public function testProtobufDecodesLikeJson(): void
    {
        ProtobufDecoder::isAvailable() or throw new SkipTest('The protobuf packages are not installed.');
        $time = new \DateTimeImmutable();

        $logs = ProtobufDecoder::logs()->decode(self::toProtobuf(
            \Opentelemetry\Proto\Collector\Logs\V1\ExportLogsServiceRequest::class,
            '{"resourceLogs":[{"scopeLogs":[{"logRecords":[{"severityNumber":9,"body":{"stringValue":"hi"},'
            . '"traceId":"W47/95gDgQPSabYzgT/GDA=="}]}]}]}',
        ), $time);
        $metrics = ProtobufDecoder::metrics()->decode(self::toProtobuf(
            \Opentelemetry\Proto\Collector\Metrics\V1\ExportMetricsServiceRequest::class,
            '{"resourceMetrics":[{"scopeMetrics":[{"metrics":[{"name":"m","sum":{"isMonotonic":true,'
            . '"dataPoints":[{"asInt":"7"}]}}]}]}]}',
        ), $time);

        Assert::instanceOf($logs, OtlpLogs::class);
        $record = $logs->resourceLogs[0]['logRecords'][0];
        Assert::same([$record['severityNumber'], $record['body'], $record['traceId']], [9, 'hi', '5b8efff798038103d269b633813fc60c']);
        Assert::instanceOf($metrics, OtlpMetrics::class);
        Assert::same($metrics->resourceMetrics[0]['metrics'][0]['dataPoints'][0]['value'], 7.0);
        Assert::same(ProtobufDecoder::logs()->createSuccessResponse()->getHeaderLine('Content-Type'), 'application/x-protobuf');
    }

    public function testProtobufCountsLogRecordsOnTheWireBeforeBuildingTheMessage(): never
    {
        // resource_logs(1) { scope_logs(2) { log_records(2) {} x 11 } }
        $scope = self::len(2, \str_repeat(self::len(2, ''), 11));
        $payload = self::len(1, $scope);
        Expect::exception(LimitExceededException::class)->withMessage('Too many log records in one request, the limit is 10.');

        ProtobufDecoder::logs(new JsonLogsDecoder(10))->decode($payload, new \DateTimeImmutable());
    }

    public function testProtobufCountsDataPointsOfEveryMetricType(): never
    {
        // resource_metrics(1) { scope_metrics(2) { metrics(2) { gauge(5) | sum(7) | ... { data_points(1) {} } } } }
        $metrics = '';
        foreach ([5, 7, 9, 10, 11] as $type) {
            $metrics .= self::len(2, self::len(1, 'name') . self::len($type, self::len(1, '') . self::len(1, '')));
        }
        $payload = self::len(1, self::len(2, $metrics));
        Expect::exception(LimitExceededException::class)->withMessageContaining('data points');

        ProtobufDecoder::metrics(new JsonMetricsDecoder(9))->decode($payload, new \DateTimeImmutable());
    }

    public function testProtobufRejectsTagThatParsersReadAsAnotherField(): never
    {
        // Tag (1 << 32) | 0x0a: field 1 to a parser that keeps 32 bits of a tag
        $payload = "\x8a\x80\x80\x80\x10" . "\x02" . self::len(2, self::len(2, ''));
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('field number out of range');

        ProtobufDecoder::logs(new JsonLogsDecoder(10))->decode($payload, new \DateTimeImmutable());
    }

    public function testProtobufRejectsGroups(): never
    {
        $payload = "\x7b\x7c" . self::len(1, self::len(2, self::len(2, '')));
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('unsupported wire type');

        ProtobufDecoder::logs(new JsonLogsDecoder(10))->decode($payload, new \DateTimeImmutable());
    }

    public function testProtobufRejectsTruncatedVarint(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('truncated varint');

        ProtobufDecoder::logs()->decode("\x0a\xff", new \DateTimeImmutable());
    }

    public function testProtobufRejectsMalformedMessage(): never
    {
        ProtobufDecoder::isAvailable() or throw new SkipTest('The protobuf packages are not installed.');
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Invalid protobuf message');

        ProtobufDecoder::logs()->decode("\x0a\xff", new \DateTimeImmutable());
    }

    /**
     * A length-delimited protobuf field; lengths below 128 only.
     */
    private static function len(int $number, string $value): string
    {
        return \chr($number << 3 | 2) . \chr(\strlen($value)) . $value;
    }

    /**
     * @param class-string<Message> $class
     */
    private static function toProtobuf(string $class, string $json): string
    {
        $message = new $class();
        $message->mergeFromJsonString($json);

        return $message->serializeToString();
    }
}
