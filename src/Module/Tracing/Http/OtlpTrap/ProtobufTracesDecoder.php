<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Tracing\Http\OtlpTrap;

use Buggregator\Trap\Module\Tracing\Frame\OtlpTraces;
use Nyholm\Psr7\Response;
use Opentelemetry\Proto\Collector\Trace\V1\ExportTraceServiceRequest;
use Psr\Http\Message\ResponseInterface;

/**
 * Decodes the OTLP/HTTP protobuf encoding.
 *
 * Needs the suggested `open-telemetry/gen-otlp-protobuf` and `google/protobuf` packages;
 * check {@see self::isAvailable()} before creating it.
 *
 * The message is converted to the plain proto3 JSON mapping and handed to {@see JsonTracesDecoder},
 * which accepts that mapping's base64 IDs and enum names, so both encodings share one validation path.
 * Its span limit applies here too.
 *
 * @internal
 * @psalm-internal Buggregator\Trap\Module\Tracing\Http
 */
final class ProtobufTracesDecoder implements TracesDecoder
{
    public const CONTENT_TYPE = 'application/x-protobuf';

    /** Field numbers of ExportTraceServiceRequest.resource_spans[].scope_spans[].spans[] */
    private const SPANS_PATH = [[1], [2], [2]];

    public function __construct(
        private readonly JsonTracesDecoder $jsonDecoder = new JsonTracesDecoder(),
    ) {}

    public static function isAvailable(): bool
    {
        return \class_exists(ExportTraceServiceRequest::class);
    }

    #[\Override]
    public function decode(string $payload, \DateTimeImmutable $time): OtlpTraces
    {
        $limit = $this->jsonDecoder->maxSpans;
        ProtobufWire::count($payload, self::SPANS_PATH, $limit) > $limit and throw LimitExceededException::spans($limit);

        $request = new ExportTraceServiceRequest();
        try {
            $request->mergeFromString($payload);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException('Invalid protobuf message: ' . $e->getMessage(), previous: $e);
        }

        return $this->jsonDecoder->decode((string) $request->serializeToJsonString(), $time);
    }

    /**
     * An empty `ExportTraceServiceResponse` encodes to zero bytes.
     */
    #[\Override]
    public function createSuccessResponse(): ResponseInterface
    {
        return new Response(200, ['Content-Type' => self::CONTENT_TYPE]);
    }
}
