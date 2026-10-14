<?php

declare(strict_types=1);

namespace Buggregator\Trap\Handler\Http\Middleware\OtlpTrap;

use Buggregator\Trap\Proto\Frame\Otlp\OtlpTraces;
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
 * @psalm-internal Buggregator\Trap\Handler\Http\Middleware
 */
final class ProtobufTracesDecoder implements TracesDecoder
{
    public const CONTENT_TYPE = 'application/x-protobuf';

    // Field numbers on the path ExportTraceServiceRequest.resource_spans[].scope_spans[].spans[]
    private const FIELD_RESOURCE_SPANS = 1;
    private const FIELD_SCOPE_SPANS = 2;
    private const FIELD_SPANS = 2;

    private const WIRE_VARINT = 0;
    private const WIRE_FIXED64 = 1;
    private const WIRE_LEN = 2;
    private const WIRE_FIXED32 = 5;

    public function __construct(
        private readonly JsonTracesDecoder $jsonDecoder = new JsonTracesDecoder(),
    ) {}

    public static function isAvailable(): bool
    {
        return \class_exists(ExportTraceServiceRequest::class);
    }

    /**
     * Counts spans on the wire, without building the message; stops counting past `$limit`.
     * Malformed data ends the count, {@see ExportTraceServiceRequest::mergeFromString()} reports it.
     */
    private static function countSpans(string $payload, int $limit): int
    {
        $count = 0;
        foreach (self::fields($payload, 0, \strlen($payload), self::FIELD_RESOURCE_SPANS) as [$from, $to]) {
            foreach (self::fields($payload, $from, $to, self::FIELD_SCOPE_SPANS) as [$scopeFrom, $scopeTo]) {
                foreach (self::fields($payload, $scopeFrom, $scopeTo, self::FIELD_SPANS) as $_) {
                    if (++$count > $limit) {
                        return $count;
                    }
                }
            }
        }

        return $count;
    }

    /**
     * Length-delimited fields of one number in a message that spans `$offset..$end` of `$bytes`.
     *
     * @return \Generator<int, array{int, int}> Offsets of the start and the end of each field value.
     */
    private static function fields(string $bytes, int $offset, int $end, int $number): \Generator
    {
        while ($offset < $end) {
            $tag = self::varint($bytes, $offset, $end);
            if ($tag === null) {
                return;
            }

            switch ($tag & 7) {
                case self::WIRE_VARINT:
                    if (self::varint($bytes, $offset, $end) === null) {
                        return;
                    }
                    break;
                case self::WIRE_FIXED64:
                    $offset += 8;
                    break;
                case self::WIRE_FIXED32:
                    $offset += 4;
                    break;
                case self::WIRE_LEN:
                    $length = self::varint($bytes, $offset, $end);
                    if ($length === null || $length > $end - $offset) {
                        return;
                    }
                    $tag >> 3 === $number and yield [$offset, $offset + $length];
                    $offset += $length;
                    break;
                default:
                    // Deprecated groups are not used by OTLP.
                    return;
            }
        }
    }

    /**
     * Reads a varint at `$offset` and moves the offset past it.
     *
     * @return int<0, max>|null Null when the varint is truncated. The 64th bit is dropped:
     *         tags and lengths never use it, and other values are only skipped.
     */
    private static function varint(string $bytes, int &$offset, int $end): ?int
    {
        $value = 0;
        for ($i = 0; $i < 10 && $offset < $end; ++$i) {
            $byte = \ord($bytes[$offset++]);
            $i < 9 and $value |= ($byte & 0x7F) << (7 * $i);
            if ($byte < 0x80) {
                \assert($value >= 0);
                return $value;
            }
        }

        return null;
    }

    #[\Override]
    public function decode(string $payload, \DateTimeImmutable $time): OtlpTraces
    {
        $limit = $this->jsonDecoder->maxSpans;
        self::countSpans($payload, $limit) > $limit and throw LimitExceededException::spans($limit);

        $request = new ExportTraceServiceRequest();
        try {
            $request->mergeFromString($payload);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException('Invalid protobuf message: ' . $e->getMessage(), previous: $e);
        }

        return $this->jsonDecoder->decode($request->serializeToJsonString(), $time);
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
