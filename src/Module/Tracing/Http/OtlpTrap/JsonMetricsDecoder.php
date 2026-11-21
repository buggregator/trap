<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Tracing\Http\OtlpTrap;

use Buggregator\Trap\Module\Tracing\Frame\OtlpMetrics;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * Decodes the OTLP/JSON encoding of `ExportMetricsServiceRequest`. Data points are reduced as
 * {@see OtlpMetrics} describes; histogram buckets and summary quantiles are dropped.
 *
 * @internal
 * @psalm-internal Buggregator\Trap\Module\Tracing\Http
 *
 * @psalm-import-type MetricData from OtlpMetrics
 * @psalm-import-type DataPointData from OtlpMetrics
 */
final class JsonMetricsDecoder implements Decoder
{
    public const CONTENT_TYPE = 'application/json';
    private const TYPES = ['gauge', 'sum', 'histogram', 'exponentialHistogram', 'summary'];

    /**
     * @param int<1, max> $maxRecords Data points accepted in one request.
     */
    public function __construct(
        public readonly int $maxRecords = self::MAX_RECORDS,
    ) {}

    #[\Override]
    public function decode(string $payload, \DateTimeImmutable $time): OtlpMetrics
    {
        $data = JsonReader::decode($payload);

        $count = 0;
        /** @var mixed $resource */
        foreach (JsonReader::arrayField($data, 'resourceMetrics') as $resource) {
            /** @var mixed $scope */
            foreach (JsonReader::arrayField($resource, 'scopeMetrics') as $scope) {
                /** @var mixed $metric */
                foreach (JsonReader::arrayField($scope, 'metrics') as $metric) {
                    foreach (self::TYPES as $type) {
                        $count += \count(JsonReader::arrayField(JsonReader::arrayField($metric, $type), 'dataPoints'));
                    }
                }
            }
        }
        $count > $this->maxRecords and throw LimitExceededException::records($this->maxRecords, 'data points');

        $result = [];
        /** @var mixed $resource */
        foreach (JsonReader::list($data, 'resourceMetrics') as $resource) {
            $resource = JsonReader::object($resource, 'resourceMetrics[]');
            $attributes = JsonReader::attributes(JsonReader::object(JsonReader::field($resource, 'resource') ?? [], 'resource'));
            /** @var mixed $scopeMetrics */
            foreach (JsonReader::list($resource, 'scopeMetrics') as $scopeMetrics) {
                $scopeMetrics = JsonReader::object($scopeMetrics, 'scopeMetrics[]');
                $scope = JsonReader::object(JsonReader::field($scopeMetrics, 'scope') ?? [], 'scope');
                $metrics = [];
                /** @var mixed $metric */
                foreach (JsonReader::list($scopeMetrics, 'metrics') as $metric) {
                    $metric = self::metric($metric);
                    $metric === null or $metrics[] = $metric;
                }
                $result[] = [
                    'resource' => ['attributes' => $attributes],
                    'scope' => ['name' => JsonReader::string($scope, 'name'), 'version' => JsonReader::string($scope, 'version')],
                    'metrics' => $metrics,
                ];
            }
        }

        return new OtlpMetrics($result, $time);
    }

    #[\Override]
    public function createSuccessResponse(): ResponseInterface
    {
        return new Response(200, ['Content-Type' => self::CONTENT_TYPE], '{}');
    }

    /**
     * @return MetricData|null Null for a metric without data, which OTLP allows.
     */
    private static function metric(mixed $data): ?array
    {
        $data = JsonReader::object($data, 'metrics[]');
        foreach (self::TYPES as $type) {
            if (!JsonReader::has($data, $type)) {
                continue;
            }

            $body = JsonReader::object(JsonReader::field($data, $type) ?? [], $type);
            $histogram = \in_array($type, ['histogram', 'exponentialHistogram', 'summary'], true);

            return [
                'name' => JsonReader::string($data, 'name'),
                'description' => JsonReader::string($data, 'description'),
                'unit' => JsonReader::string($data, 'unit'),
                'type' => $type,
                'monotonic' => $type === 'sum' && JsonReader::has($body, 'isMonotonic') && JsonReader::bool($body, 'isMonotonic'),
                'dataPoints' => \array_map(
                    static fn(mixed $point): array => self::dataPoint($point, $histogram),
                    JsonReader::list($body, 'dataPoints'),
                ),
            ];
        }

        return null;
    }

    /**
     * @return DataPointData
     */
    private static function dataPoint(mixed $data, bool $histogram): array
    {
        $data = JsonReader::object($data, 'dataPoints[]');

        $traceId = $spanId = null;
        /** @var mixed $exemplar */
        foreach (JsonReader::list($data, 'exemplars') as $exemplar) {
            $exemplar = JsonReader::object($exemplar, 'exemplars[]');
            $traceId = JsonReader::id($exemplar, 'traceId', JsonReader::TRACE_ID_BYTES);
            $spanId = JsonReader::id($exemplar, 'spanId', JsonReader::SPAN_ID_BYTES);
            if ($traceId !== null) {
                break;
            }
        }

        $point = [
            'timeUnixNano' => JsonReader::timestamp($data, 'timeUnixNano'),
            'attributes' => JsonReader::attributes($data),
            'value' => 0.0,
            'count' => null,
            'sum' => null,
            'min' => null,
            'max' => null,
            'traceId' => $traceId,
            'spanId' => $spanId,
        ];

        if (!$histogram) {
            $point['value'] = match (true) {
                JsonReader::has($data, 'asDouble') => self::number(JsonReader::field($data, 'asDouble')),
                JsonReader::has($data, 'asInt') => (float) JsonReader::int64($data, 'asInt'),
                default => 0.0,
            };

            return $point;
        }

        $count = JsonReader::integer($data, 'count', 0, \PHP_INT_MAX, clamp: true);
        $point['count'] = $count;
        foreach (['sum', 'min', 'max'] as $key) {
            JsonReader::has($data, $key) and $point[$key] = self::number(JsonReader::field($data, $key));
        }
        $point['value'] = $count > 0 && $point['sum'] !== null ? $point['sum'] / (float) $count : $point['sum'] ?? 0.0;

        return $point;
    }

    /**
     * A finite double; `NaN` and infinities read as 0.
     */
    private static function number(mixed $value): float
    {
        $double = JsonReader::double($value);

        return \is_float($double) ? $double : 0.0;
    }
}
