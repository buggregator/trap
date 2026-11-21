<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Tracing\Http\OtlpTrap;

use Buggregator\Trap\Module\Tracing\Frame\OtlpTraces;
use Buggregator\Trap\Module\Tracing\Frame\Trace\ResourceSpans;
use Buggregator\Trap\Module\Tracing\Frame\Trace\ScopeSpans;
use Buggregator\Trap\Module\Tracing\Frame\Trace\Span;
use Buggregator\Trap\Module\Tracing\Frame\Trace\SpanEvent;
use Buggregator\Trap\Module\Tracing\Frame\Trace\SpanKind;
use Buggregator\Trap\Module\Tracing\Frame\Trace\SpanLink;
use Buggregator\Trap\Module\Tracing\Frame\Trace\SpanStatus;
use Buggregator\Trap\Module\Tracing\Frame\Trace\StatusCode;
use Buggregator\Trap\Support\Json;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * Decodes the OTLP/JSON encoding: the proto3 JSON mapping of `ExportTraceServiceRequest`
 * with trace and span IDs as hex strings, read by {@see JsonReader}.
 *
 * @internal
 * @psalm-internal Buggregator\Trap\Module\Tracing\Http
 */
final class JsonTracesDecoder implements TracesDecoder
{
    public const CONTENT_TYPE = 'application/json';

    /**
     * @param int<1, max> $maxSpans Spans accepted in one request.
     */
    public function __construct(
        public readonly int $maxSpans = self::MAX_SPANS,
    ) {}

    #[\Override]
    public function decode(string $payload, \DateTimeImmutable $time): OtlpTraces
    {
        // Big integers as strings: as floats they would lose precision before the range checks.
        $data = \json_decode($payload, true, 512, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
        // A list like `[1,2]` decodes to an array as well, and `[]` is indistinguishable from `{}`.
        \is_array($data) && $payload[\strspn($payload, " \t\n\r")] === '{'
            or throw new \InvalidArgumentException('The request body must be a JSON object.');

        // Before building the span objects, which would add to the memory of the decoded arrays.
        self::countSpans($data) > $this->maxSpans and throw LimitExceededException::spans($this->maxSpans);

        return new OtlpTraces(
            \array_map(self::resourceSpans(...), JsonReader::list($data, 'resourceSpans')),
            $time,
        );
    }

    #[\Override]
    public function createSuccessResponse(): ResponseInterface
    {
        return new Response(200, ['Content-Type' => self::CONTENT_TYPE], '{}');
    }

    /**
     * Counts spans without validating the structure; malformed parts count as empty and fail later.
     */
    private static function countSpans(array $data): int
    {
        $count = 0;
        /** @var mixed $resource */
        foreach (JsonReader::arrayField($data, 'resourceSpans') as $resource) {
            /** @var mixed $scope */
            foreach (JsonReader::arrayField($resource, 'scopeSpans') as $scope) {
                $count += \count(JsonReader::arrayField($scope, 'spans'));
            }
        }

        return $count;
    }

    private static function resourceSpans(mixed $data): ResourceSpans
    {
        $data = JsonReader::object($data, 'resourceSpans[]');
        $resource = JsonReader::object(JsonReader::field($data, 'resource') ?? [], 'resource');

        return new ResourceSpans(
            scopeSpans: \array_map(self::scopeSpans(...), JsonReader::list($data, 'scopeSpans')),
            resourceAttributes: JsonReader::attributes($resource),
            resourceDroppedAttributesCount: JsonReader::uint32($resource, 'droppedAttributesCount'),
            schemaUrl: JsonReader::string($data, 'schemaUrl'),
        );
    }

    private static function scopeSpans(mixed $data): ScopeSpans
    {
        $data = JsonReader::object($data, 'scopeSpans[]');
        $scope = JsonReader::object(JsonReader::field($data, 'scope') ?? [], 'scope');

        return new ScopeSpans(
            spans: \array_map(self::span(...), JsonReader::list($data, 'spans')),
            scopeName: JsonReader::string($scope, 'name'),
            scopeVersion: JsonReader::string($scope, 'version'),
            scopeAttributes: JsonReader::attributes($scope),
            scopeDroppedAttributesCount: JsonReader::uint32($scope, 'droppedAttributesCount'),
            schemaUrl: JsonReader::string($data, 'schemaUrl'),
        );
    }

    private static function span(mixed $data): Span
    {
        $data = JsonReader::object($data, 'spans[]');
        $status = JsonReader::object(JsonReader::field($data, 'status') ?? [], 'status');

        return new Span(
            traceId: JsonReader::id($data, 'traceId', JsonReader::TRACE_ID_BYTES)
                ?? throw new \InvalidArgumentException('Span `traceId` is required and must not be all zeros.'),
            spanId: JsonReader::id($data, 'spanId', JsonReader::SPAN_ID_BYTES)
                ?? throw new \InvalidArgumentException('Span `spanId` is required and must not be all zeros.'),
            parentSpanId: JsonReader::id($data, 'parentSpanId', JsonReader::SPAN_ID_BYTES),
            name: JsonReader::string($data, 'name'),
            kind: SpanKind::tryFrom(JsonReader::enum($data, 'kind', 'SPAN_KIND_', SpanKind::cases())) ?? SpanKind::Unspecified,
            startTimeUnixNano: JsonReader::timestamp($data, 'startTimeUnixNano'),
            endTimeUnixNano: JsonReader::timestamp($data, 'endTimeUnixNano'),
            attributes: JsonReader::attributes($data),
            events: \array_map(self::event(...), JsonReader::list($data, 'events')),
            links: \array_map(self::link(...), JsonReader::list($data, 'links')),
            status: new SpanStatus(
                StatusCode::tryFrom(JsonReader::enum($status, 'code', 'STATUS_CODE_', StatusCode::cases())) ?? StatusCode::Unset,
                JsonReader::string($status, 'message'),
            ),
            traceState: JsonReader::string($data, 'traceState'),
            flags: JsonReader::uint32($data, 'flags'),
            droppedAttributesCount: JsonReader::uint32($data, 'droppedAttributesCount'),
            droppedEventsCount: JsonReader::uint32($data, 'droppedEventsCount'),
            droppedLinksCount: JsonReader::uint32($data, 'droppedLinksCount'),
        );
    }

    private static function event(mixed $data): SpanEvent
    {
        $data = JsonReader::object($data, 'events[]');

        return new SpanEvent(
            timeUnixNano: JsonReader::timestamp($data, 'timeUnixNano'),
            name: JsonReader::string($data, 'name'),
            attributes: JsonReader::attributes($data),
            droppedAttributesCount: JsonReader::uint32($data, 'droppedAttributesCount'),
        );
    }

    private static function link(mixed $data): SpanLink
    {
        $data = JsonReader::object($data, 'links[]');

        return new SpanLink(
            traceId: JsonReader::id($data, 'traceId', JsonReader::TRACE_ID_BYTES)
                ?? throw new \InvalidArgumentException('Link `traceId` is required and must not be all zeros.'),
            spanId: JsonReader::id($data, 'spanId', JsonReader::SPAN_ID_BYTES)
                ?? throw new \InvalidArgumentException('Link `spanId` is required and must not be all zeros.'),
            traceState: JsonReader::string($data, 'traceState'),
            flags: JsonReader::uint32($data, 'flags'),
            attributes: JsonReader::attributes($data),
            droppedAttributesCount: JsonReader::uint32($data, 'droppedAttributesCount'),
        );
    }
}
