<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Tracing\Http\OtlpTrap;

use Buggregator\Trap\Module\Tracing\Frame;
use Google\Protobuf\Internal\Message;
use Nyholm\Psr7\Response;
use Opentelemetry\Proto\Collector\Logs\V1\ExportLogsServiceRequest;
use Opentelemetry\Proto\Collector\Metrics\V1\ExportMetricsServiceRequest;
use Psr\Http\Message\ResponseInterface;

/**
 * Decodes the OTLP/HTTP protobuf encoding of logs and metrics, as {@see ProtobufTracesDecoder} does for traces:
 * the message is converted to the proto3 JSON mapping and handed to the JSON decoder of the signal.
 *
 * Needs the suggested `open-telemetry/gen-otlp-protobuf` and `google/protobuf` packages;
 * check {@see self::isAvailable()} before creating it.
 *
 * @internal
 * @psalm-internal Buggregator\Trap\Module\Tracing\Http
 */
final class ProtobufDecoder implements Decoder
{
    public const CONTENT_TYPE = 'application/x-protobuf';

    /** Field numbers of ExportLogsServiceRequest.resource_logs[].scope_logs[].log_records[] */
    private const LOG_RECORDS_PATH = [[1], [2], [2]];

    /**
     * Field numbers of ExportMetricsServiceRequest.resource_metrics[].scope_metrics[].metrics[], then of
     * the gauge, sum, histogram, exponential_histogram or summary of a metric, then of its data_points[].
     */
    private const DATA_POINTS_PATH = [[1], [2], [2], [5, 7, 9, 10, 11], [1]];

    /**
     * @param \Closure(): Message $newRequest
     * @param non-empty-list<non-empty-list<int>> $recordsPath Where the records lie on the wire.
     * @param int<1, max> $maxRecords
     * @param string $records What the records are, for the error message.
     */
    private function __construct(
        private readonly \Closure $newRequest,
        private readonly Decoder $jsonDecoder,
        private readonly array $recordsPath,
        private readonly int $maxRecords,
        private readonly string $records,
    ) {}

    public static function isAvailable(): bool
    {
        return \class_exists(ExportLogsServiceRequest::class) && \class_exists(ExportMetricsServiceRequest::class);
    }

    public static function logs(JsonLogsDecoder $json = new JsonLogsDecoder()): self
    {
        return new self(
            static fn(): Message => new ExportLogsServiceRequest(),
            $json,
            self::LOG_RECORDS_PATH,
            $json->maxRecords,
            'log records',
        );
    }

    public static function metrics(JsonMetricsDecoder $json = new JsonMetricsDecoder()): self
    {
        return new self(
            static fn(): Message => new ExportMetricsServiceRequest(),
            $json,
            self::DATA_POINTS_PATH,
            $json->maxRecords,
            'data points',
        );
    }

    /**
     * The records are counted on the wire before the message is built: building it is what takes the time
     * and the memory.
     */
    #[\Override]
    public function decode(string $payload, \DateTimeImmutable $time): Frame
    {
        ProtobufWire::count($payload, $this->recordsPath, $this->maxRecords) > $this->maxRecords
            and throw LimitExceededException::records($this->maxRecords, $this->records);

        $request = ($this->newRequest)();
        try {
            $request->mergeFromString($payload);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException('Invalid protobuf message: ' . $e->getMessage(), previous: $e);
        }

        return $this->jsonDecoder->decode((string) $request->serializeToJsonString(), $time);
    }

    /**
     * An empty export response encodes to zero bytes.
     */
    #[\Override]
    public function createSuccessResponse(): ResponseInterface
    {
        return new Response(200, ['Content-Type' => self::CONTENT_TYPE]);
    }
}
