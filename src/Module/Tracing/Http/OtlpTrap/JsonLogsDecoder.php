<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Tracing\Http\OtlpTrap;

use Buggregator\Trap\Module\Tracing\Frame\OtlpLogs;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * Decodes the OTLP/JSON encoding of `ExportLogsServiceRequest`.
 *
 * @internal
 * @psalm-internal Buggregator\Trap\Module\Tracing\Http
 *
 * @psalm-import-type ResourceLogsData from OtlpLogs
 * @psalm-import-type LogRecordData from OtlpLogs
 */
final class JsonLogsDecoder implements Decoder
{
    public const CONTENT_TYPE = 'application/json';
    private const SEVERITY_BASE = ['TRACE' => 1, 'DEBUG' => 5, 'INFO' => 9, 'WARN' => 13, 'ERROR' => 17, 'FATAL' => 21];

    /**
     * @param int<1, max> $maxRecords Log records accepted in one request.
     */
    public function __construct(
        public readonly int $maxRecords = self::MAX_RECORDS,
    ) {}

    #[\Override]
    public function decode(string $payload, \DateTimeImmutable $time): OtlpLogs
    {
        $data = JsonReader::decode($payload);

        $count = 0;
        /** @var mixed $resource */
        foreach (JsonReader::arrayField($data, 'resourceLogs') as $resource) {
            /** @var mixed $scope */
            foreach (JsonReader::arrayField($resource, 'scopeLogs') as $scope) {
                $count += \count(JsonReader::arrayField($scope, 'logRecords'));
            }
        }
        $count > $this->maxRecords and throw LimitExceededException::records($this->maxRecords, 'log records');

        $result = [];
        /** @var mixed $resource */
        foreach (JsonReader::list($data, 'resourceLogs') as $resource) {
            $resource = JsonReader::object($resource, 'resourceLogs[]');
            $attributes = JsonReader::attributes(JsonReader::object(JsonReader::field($resource, 'resource') ?? [], 'resource'));
            /** @var mixed $scopeLogs */
            foreach (JsonReader::list($resource, 'scopeLogs') as $scopeLogs) {
                $scopeLogs = JsonReader::object($scopeLogs, 'scopeLogs[]');
                $scope = JsonReader::object(JsonReader::field($scopeLogs, 'scope') ?? [], 'scope');
                $result[] = [
                    'resource' => ['attributes' => $attributes],
                    'scope' => ['name' => JsonReader::string($scope, 'name'), 'version' => JsonReader::string($scope, 'version')],
                    'logRecords' => \array_map(self::record(...), JsonReader::list($scopeLogs, 'logRecords')),
                ];
            }
        }

        return new OtlpLogs($result, $time);
    }

    #[\Override]
    public function createSuccessResponse(): ResponseInterface
    {
        return new Response(200, ['Content-Type' => self::CONTENT_TYPE], '{}');
    }

    /**
     * @return LogRecordData
     */
    private static function record(mixed $data): array
    {
        $data = JsonReader::object($data, 'logRecords[]');

        return [
            'timeUnixNano' => JsonReader::timestamp($data, 'timeUnixNano'),
            'observedTimeUnixNano' => JsonReader::timestamp($data, 'observedTimeUnixNano'),
            'severityNumber' => self::severity($data),
            'severityText' => JsonReader::string($data, 'severityText'),
            'body' => JsonReader::anyValue(JsonReader::field($data, 'body')),
            'attributes' => JsonReader::attributes($data),
            'traceId' => JsonReader::id($data, 'traceId', JsonReader::TRACE_ID_BYTES),
            'spanId' => JsonReader::id($data, 'spanId', JsonReader::SPAN_ID_BYTES),
        ];
    }

    /**
     * A number, or a name like `SEVERITY_NUMBER_INFO2` as the proto3 JSON mapping writes enums.
     */
    private static function severity(array $data): int
    {
        /** @var mixed $value */
        $value = JsonReader::field($data, 'severityNumber');
        if (\is_string($value) && \preg_match('/^SEVERITY_NUMBER_([A-Z]++)([1-4]?)$/', $value, $match) === 1) {
            return isset(self::SEVERITY_BASE[$match[1]])
                ? self::SEVERITY_BASE[$match[1]] + \max(0, (int) $match[2] - 1)
                : 0;
        }

        return JsonReader::integer($data, 'severityNumber', 0, 24);
    }
}
