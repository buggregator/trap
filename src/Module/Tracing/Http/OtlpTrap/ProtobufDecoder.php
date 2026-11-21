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

    /**
     * @param \Closure(): Message $newRequest
     * @param 'logs'|'metrics' $signal
     * @param int<1, max> $maxRecords
     * @param string $records What the records are, for the error message.
     */
    private function __construct(
        private readonly \Closure $newRequest,
        private readonly Decoder $jsonDecoder,
        private readonly string $signal,
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
            'logs',
            $json->maxRecords,
            'log records',
        );
    }

    public static function metrics(JsonMetricsDecoder $json = new JsonMetricsDecoder()): self
    {
        return new self(
            static fn(): Message => new ExportMetricsServiceRequest(),
            $json,
            'metrics',
            $json->maxRecords,
            'data points',
        );
    }

    /**
     * The message is checked on the wire before it is built: building it is what takes the time and the memory.
     */
    #[\Override]
    public function decode(string $payload, \DateTimeImmutable $time): Frame
    {
        ProtobufWire::check($payload, $this->signal, $this->maxRecords, $this->records);

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
