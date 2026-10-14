<?php

declare(strict_types=1);

namespace Buggregator\Trap\Handler\Http\Middleware\OtlpTrap;

use Buggregator\Trap\Proto\Frame\Otlp\OtlpTraces;
use Buggregator\Trap\Proto\Frame\Otlp\Trace\Attributes;
use Buggregator\Trap\Proto\Frame\Otlp\Trace\ResourceSpans;
use Buggregator\Trap\Proto\Frame\Otlp\Trace\ScopeSpans;
use Buggregator\Trap\Proto\Frame\Otlp\Trace\Span;
use Buggregator\Trap\Proto\Frame\Otlp\Trace\SpanEvent;
use Buggregator\Trap\Proto\Frame\Otlp\Trace\SpanKind;
use Buggregator\Trap\Proto\Frame\Otlp\Trace\SpanLink;
use Buggregator\Trap\Proto\Frame\Otlp\Trace\SpanStatus;
use Buggregator\Trap\Proto\Frame\Otlp\Trace\StatusCode;
use Buggregator\Trap\Support\Json;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * Decodes the OTLP/JSON encoding: the proto3 JSON mapping of `ExportTraceServiceRequest`
 * with trace and span IDs as hex strings.
 *
 * Proto3 JSON lets a sender put 64-bit integers either as numbers or as strings, and enums
 * either as numbers or as names; both forms are accepted.
 *
 * @internal
 * @psalm-internal Buggregator\Trap\Handler\Http\Middleware
 *
 * @psalm-import-type Attributes from Attributes
 * @psalm-import-type AttributeValue from Attributes
 */
final class JsonTracesDecoder implements TracesDecoder
{
    public const CONTENT_TYPE = 'application/json';
    private const TRACE_ID_BYTES = 16;
    private const SPAN_ID_BYTES = 8;

    #[\Override]
    public function decode(string $payload, \DateTimeImmutable $time): OtlpTraces
    {
        $data = Json::decode($payload);
        \is_array($data) or throw new \InvalidArgumentException('The request body must be a JSON object.');

        return new OtlpTraces(
            \array_map(self::resourceSpans(...), self::list($data, 'resourceSpans')),
            $time,
        );
    }

    #[\Override]
    public function createSuccessResponse(): ResponseInterface
    {
        return new Response(200, ['Content-Type' => self::CONTENT_TYPE], '{}');
    }

    private static function resourceSpans(mixed $data): ResourceSpans
    {
        $data = self::object($data, 'resourceSpans[]');
        $resource = self::object($data['resource'] ?? [], 'resource');

        return new ResourceSpans(
            scopeSpans: \array_map(self::scopeSpans(...), self::list($data, 'scopeSpans')),
            resourceAttributes: self::attributes($resource),
            resourceDroppedAttributesCount: self::count($resource, 'droppedAttributesCount'),
            schemaUrl: self::string($data, 'schemaUrl'),
        );
    }

    private static function scopeSpans(mixed $data): ScopeSpans
    {
        $data = self::object($data, 'scopeSpans[]');
        $scope = self::object($data['scope'] ?? [], 'scope');

        return new ScopeSpans(
            spans: \array_map(self::span(...), self::list($data, 'spans')),
            scopeName: self::string($scope, 'name'),
            scopeVersion: self::string($scope, 'version'),
            scopeAttributes: self::attributes($scope),
            scopeDroppedAttributesCount: self::count($scope, 'droppedAttributesCount'),
            schemaUrl: self::string($data, 'schemaUrl'),
        );
    }

    private static function span(mixed $data): Span
    {
        $data = self::object($data, 'spans[]');
        $status = self::object($data['status'] ?? [], 'status');

        return new Span(
            traceId: self::id($data, 'traceId', self::TRACE_ID_BYTES)
                ?? throw new \InvalidArgumentException('Span `traceId` is required.'),
            spanId: self::id($data, 'spanId', self::SPAN_ID_BYTES)
                ?? throw new \InvalidArgumentException('Span `spanId` is required.'),
            parentSpanId: self::id($data, 'parentSpanId', self::SPAN_ID_BYTES),
            name: self::string($data, 'name'),
            kind: SpanKind::tryFrom(self::enum($data, 'kind', 'SPAN_KIND_', SpanKind::cases())) ?? SpanKind::Unspecified,
            startTimeUnixNano: self::int($data, 'startTimeUnixNano'),
            endTimeUnixNano: self::int($data, 'endTimeUnixNano'),
            attributes: self::attributes($data),
            events: \array_map(self::event(...), self::list($data, 'events')),
            links: \array_map(self::link(...), self::list($data, 'links')),
            status: new SpanStatus(
                StatusCode::tryFrom(self::enum($status, 'code', 'STATUS_CODE_', StatusCode::cases())) ?? StatusCode::Unset,
                self::string($status, 'message'),
            ),
            traceState: self::string($data, 'traceState'),
            flags: self::int($data, 'flags'),
            droppedAttributesCount: self::count($data, 'droppedAttributesCount'),
            droppedEventsCount: self::count($data, 'droppedEventsCount'),
            droppedLinksCount: self::count($data, 'droppedLinksCount'),
        );
    }

    private static function event(mixed $data): SpanEvent
    {
        $data = self::object($data, 'events[]');

        return new SpanEvent(
            timeUnixNano: self::int($data, 'timeUnixNano'),
            name: self::string($data, 'name'),
            attributes: self::attributes($data),
            droppedAttributesCount: self::count($data, 'droppedAttributesCount'),
        );
    }

    private static function link(mixed $data): SpanLink
    {
        $data = self::object($data, 'links[]');

        return new SpanLink(
            traceId: self::id($data, 'traceId', self::TRACE_ID_BYTES)
                ?? throw new \InvalidArgumentException('Link `traceId` is required.'),
            spanId: self::id($data, 'spanId', self::SPAN_ID_BYTES)
                ?? throw new \InvalidArgumentException('Link `spanId` is required.'),
            traceState: self::string($data, 'traceState'),
            flags: self::int($data, 'flags'),
            attributes: self::attributes($data),
            droppedAttributesCount: self::count($data, 'droppedAttributesCount'),
        );
    }

    /**
     * Reads a `repeated KeyValue` field into a map; a repeated key keeps the last value.
     *
     * @return Attributes
     */
    private static function attributes(array $data, string $key = 'attributes'): array
    {
        $result = [];
        /** @var mixed $item */
        foreach (self::list($data, $key) as $item) {
            $item = self::object($item, $key . '[]');
            $name = $item['key'] ?? null;
            \is_string($name) or throw new \InvalidArgumentException(\sprintf('`%s[].key` must be a string.', $key));
            $result[$name] = self::anyValue($item['value'] ?? null);
        }

        return $result;
    }

    /**
     * @return AttributeValue
     */
    private static function anyValue(mixed $value): mixed
    {
        // An unset AnyValue is valid and means an empty value.
        if ($value === null || $value === []) {
            return null;
        }

        $value = self::object($value, 'value');

        return match (true) {
            \array_key_exists('stringValue', $value) => self::string($value, 'stringValue'),
            \array_key_exists('boolValue', $value) => \is_bool($value['boolValue'])
                ? $value['boolValue']
                : throw new \InvalidArgumentException('`boolValue` must be a boolean.'),
            \array_key_exists('intValue', $value) => self::int($value, 'intValue'),
            \array_key_exists('doubleValue', $value) => self::double($value['doubleValue']),
            \array_key_exists('bytesValue', $value) => self::string($value, 'bytesValue'),
            \array_key_exists('arrayValue', $value) => \array_map(
                self::anyValue(...),
                self::list(self::object($value['arrayValue'] ?? [], 'arrayValue'), 'values'),
            ),
            \array_key_exists('kvlistValue', $value) => self::attributes(
                self::object($value['kvlistValue'] ?? [], 'kvlistValue'),
                'values',
            ),
            default => null,
        };
    }

    /**
     * Non-finite doubles stay as their proto3 JSON names because JSON numbers cannot carry them.
     */
    private static function double(mixed $value): float|string
    {
        return match (true) {
            \is_float($value) => $value,
            \is_int($value) => (float) $value,
            \in_array($value, ['NaN', 'Infinity', '-Infinity'], true) => $value,
            \is_string($value) && \is_numeric($value) => (float) $value,
            default => throw new \InvalidArgumentException('`doubleValue` must be a number.'),
        };
    }

    /**
     * Accepts lowercase or uppercase hex as the OTLP/JSON spec requires, and base64 as the plain
     * proto3 JSON mapping of `bytes` produces.
     *
     * @param int<1, max> $bytes
     * @return non-empty-string|null Lowercase hex; null when the field is absent or empty.
     */
    private static function id(array $data, string $key, int $bytes): ?string
    {
        $value = self::string($data, $key);
        if ($value === '') {
            return null;
        }

        if (\strlen($value) === $bytes * 2 && \preg_match('/^[0-9a-f]++$/i', $value) === 1) {
            return \strtolower($value);
        }

        $binary = \base64_decode($value, true);
        if ($binary !== false && $binary !== '' && \strlen($binary) === $bytes) {
            /** @var non-empty-string */
            return \bin2hex($binary);
        }

        throw new \InvalidArgumentException(\sprintf('`%s` must be a %d-byte hex string.', $key, $bytes));
    }

    /**
     * @param list<SpanKind>|list<StatusCode> $cases
     * @return int Zero when the field is absent.
     */
    private static function enum(array $data, string $key, string $prefix, array $cases): int
    {
        /** @var mixed $value */
        $value = $data[$key] ?? 0;
        if (\is_string($value) && \str_starts_with($value, $prefix)) {
            foreach ($cases as $case) {
                if ($prefix . \strtoupper($case->name) === $value) {
                    return $case->value;
                }
            }

            return 0;
        }

        return self::int($data, $key);
    }

    private static function int(array $data, string $key): int
    {
        /** @var mixed $value */
        $value = $data[$key] ?? 0;
        return match (true) {
            \is_int($value) => $value,
            \is_string($value) && \preg_match('/^-?\d++$/', $value) === 1 => (int) $value,
            \is_float($value) && \floor($value) === $value => (int) $value,
            default => throw new \InvalidArgumentException(\sprintf('`%s` must be an integer.', $key)),
        };
    }

    /**
     * @return int<0, max>
     */
    private static function count(array $data, string $key): int
    {
        return \max(0, self::int($data, $key));
    }

    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? '';
        \is_string($value) or throw new \InvalidArgumentException(\sprintf('`%s` must be a string.', $key));

        return $value;
    }

    /**
     * @return list<mixed>
     */
    private static function list(array $data, string $key): array
    {
        $value = $data[$key] ?? [];
        \is_array($value) && \array_is_list($value)
            or throw new \InvalidArgumentException(\sprintf('`%s` must be an array.', $key));

        return $value;
    }

    private static function object(mixed $value, string $name): array
    {
        if (\is_array($value) && ($value === [] || !\array_is_list($value))) {
            return $value;
        }

        throw new \InvalidArgumentException(\sprintf('`%s` must be an object.', $name));
    }
}
