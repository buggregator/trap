<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Tracing\Frame;

use Buggregator\Trap\Module\Tracing\Frame;
use Buggregator\Trap\Module\Tracing\Frame\Trace\Attributes;
use Buggregator\Trap\Support\Json;

/**
 * One OTLP logs export request, `ExportLogsServiceRequest`.
 *
 * Serialized as a single JSON line: `{"type": "otlp.logs", "resourceLogs": [...]}`, with lowercase hex IDs,
 * integer timestamps and plain `{"key": value}` attribute maps.
 *
 * @internal
 * @psalm-internal Buggregator
 *
 * @psalm-import-type AttributeMap from Attributes
 * @psalm-import-type AttributeValue from Attributes
 * @psalm-type LogRecordData = array{
 *     timeUnixNano: int<0, max>,
 *     observedTimeUnixNano: int<0, max>,
 *     severityNumber: int,
 *     severityText: string,
 *     body: AttributeValue,
 *     attributes: AttributeMap,
 *     traceId: non-empty-string|null,
 *     spanId: non-empty-string|null
 * }
 * @psalm-type ResourceLogsData = array{
 *     resource: array{attributes: AttributeMap},
 *     scope: array{name: string, version: string},
 *     logRecords: list<LogRecordData>
 * }
 * @psalm-type OtlpLogsData = array{
 *     type: OtlpLogs::OTLP_FRAME_TYPE,
 *     resourceLogs: list<ResourceLogsData>,
 *     project?: string|null
 * }
 */
final class OtlpLogs extends Frame
{
    public const OTLP_FRAME_TYPE = 'otlp.logs';

    /**
     * @param list<ResourceLogsData> $resourceLogs
     * @param string|null $project The project the request names; the default project when null.
     */
    public function __construct(
        public readonly array $resourceLogs,
        \DateTimeImmutable $time = new \DateTimeImmutable(),
        public readonly ?string $project = null,
    ) {
        parent::__construct($time);
    }

    /**
     * @param OtlpLogsData $data
     */
    public static function fromArray(array $data, \DateTimeImmutable $time): self
    {
        return new self($data['resourceLogs'], $time, $data['project'] ?? null);
    }

    public function withProject(?string $project): self
    {
        return new self($this->resourceLogs, $this->time, $project);
    }

    /**
     * @return int<0, max>
     */
    public function countRecords(): int
    {
        $count = 0;
        foreach ($this->resourceLogs as $resourceLogs) {
            $count += \count($resourceLogs['logRecords']);
        }

        return $count;
    }

    /**
     * @throws \JsonException
     */
    public function __toString(): string
    {
        $resourceLogs = [];
        foreach ($this->resourceLogs as $item) {
            $item['resource']['attributes'] = Attributes::serialize($item['resource']['attributes']);
            $records = [];
            foreach ($item['logRecords'] as $record) {
                $record['attributes'] = Attributes::serialize($record['attributes']);
                $records[] = $record;
            }
            $item['logRecords'] = $records;
            $resourceLogs[] = $item;
        }

        return Json::encode(
            ['type' => self::OTLP_FRAME_TYPE, 'resourceLogs' => $resourceLogs]
            + ($this->project === null ? [] : ['project' => $this->project]),
            \JSON_PRESERVE_ZERO_FRACTION,
        );
    }
}
