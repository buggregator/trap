<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Tracing\Frame;

use Buggregator\Trap\Module\Tracing\Frame;
use Buggregator\Trap\Module\Tracing\Frame\Trace\Attributes;
use Buggregator\Trap\Support\Json;

/**
 * One OTLP metrics export request, `ExportMetricsServiceRequest`, with each data point reduced to one value.
 *
 * A gauge and a sum keep their value. A histogram, an exponential histogram and a summary keep
 * `count`, `sum`, `min` and `max`, and their value is the mean.
 *
 * Serialized as a single JSON line: `{"type": "otlp.metrics", "resourceMetrics": [...]}`.
 *
 * @internal
 * @psalm-internal Buggregator
 *
 * @psalm-import-type AttributeMap from Attributes
 * @psalm-type DataPointData = array{
 *     timeUnixNano: int<0, max>,
 *     attributes: AttributeMap,
 *     value: float,
 *     count: int|null,
 *     sum: float|null,
 *     min: float|null,
 *     max: float|null,
 *     traceId: non-empty-string|null,
 *     spanId: non-empty-string|null
 * }
 * @psalm-type MetricData = array{
 *     name: string,
 *     description: string,
 *     unit: string,
 *     type: 'gauge'|'sum'|'histogram'|'exponentialHistogram'|'summary',
 *     monotonic: bool,
 *     dataPoints: list<DataPointData>
 * }
 * @psalm-type ResourceMetricsData = array{
 *     resource: array{attributes: AttributeMap},
 *     scope: array{name: string, version: string},
 *     metrics: list<MetricData>
 * }
 * @psalm-type OtlpMetricsData = array{
 *     type: OtlpMetrics::OTLP_FRAME_TYPE,
 *     resourceMetrics: list<ResourceMetricsData>,
 *     project?: string|null
 * }
 */
final class OtlpMetrics extends Frame
{
    public const OTLP_FRAME_TYPE = 'otlp.metrics';

    /**
     * @param list<ResourceMetricsData> $resourceMetrics
     * @param string|null $project The project the request names; the default project when null.
     */
    public function __construct(
        public readonly array $resourceMetrics,
        \DateTimeImmutable $time = new \DateTimeImmutable(),
        public readonly ?string $project = null,
    ) {
        parent::__construct($time);
    }

    /**
     * @param OtlpMetricsData $data
     */
    public static function fromArray(array $data, \DateTimeImmutable $time): self
    {
        return new self($data['resourceMetrics'], $time, $data['project'] ?? null);
    }

    public function withProject(?string $project): self
    {
        return new self($this->resourceMetrics, $this->time, $project);
    }

    /**
     * @return int<0, max>
     */
    public function countDataPoints(): int
    {
        $count = 0;
        foreach ($this->resourceMetrics as $resourceMetrics) {
            foreach ($resourceMetrics['metrics'] as $metric) {
                $count += \count($metric['dataPoints']);
            }
        }

        return $count;
    }

    /**
     * @throws \JsonException
     */
    public function __toString(): string
    {
        $resourceMetrics = [];
        foreach ($this->resourceMetrics as $item) {
            $item['resource']['attributes'] = Attributes::serialize($item['resource']['attributes']);
            $metrics = [];
            foreach ($item['metrics'] as $metric) {
                $points = [];
                foreach ($metric['dataPoints'] as $point) {
                    $point['attributes'] = Attributes::serialize($point['attributes']);
                    $points[] = $point;
                }
                $metric['dataPoints'] = $points;
                $metrics[] = $metric;
            }
            $item['metrics'] = $metrics;
            $resourceMetrics[] = $item;
        }

        return Json::encode(
            ['type' => self::OTLP_FRAME_TYPE, 'resourceMetrics' => $resourceMetrics]
            + ($this->project === null ? [] : ['project' => $this->project]),
            \JSON_PRESERVE_ZERO_FRACTION,
        );
    }
}
