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
 * either as numbers or as names; both forms are accepted. Integers outside their protobuf type
 * are rejected, except timestamps above `PHP_INT_MAX`, which are clamped to it.
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
    private const UINT32_MAX = 0xFFFF_FFFF;

    /**
     * A decoded JSON object takes about 400 bytes, so a body of tiny objects like `{"a":1}` grows
     * about 50 times in json_decode(). The limit keeps that under about 90 MB; real spans stay well
     * below it within the body size limit of {@see \Buggregator\Trap\Handler\Http\Middleware\OtlpTrap}.
     */
    public const MAX_JSON_CONTAINERS = 200_000;

    /**
     * @param int<1, max> $maxSpans Spans accepted in one request.
     * @param int<1, max> $maxJsonContainers JSON objects and arrays accepted in one request,
     *        estimated by counting `{` and `[`, those in strings included.
     */
    public function __construct(
        public readonly int $maxSpans = self::MAX_SPANS,
        private readonly int $maxJsonContainers = self::MAX_JSON_CONTAINERS,
    ) {}

    #[\Override]
    public function decode(string $payload, \DateTimeImmutable $time): OtlpTraces
    {
        \substr_count($payload, '{') + \substr_count($payload, '[') > $this->maxJsonContainers
            and throw LimitExceededException::jsonContainers($this->maxJsonContainers);

        // Big integers as strings: as floats they would lose precision before the range checks.
        $data = \json_decode($payload, true, 512, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
        // A list like `[1,2]` decodes to an array as well, and `[]` is indistinguishable from `{}`.
        \is_array($data) && $payload[\strspn($payload, " \t\n\r")] === '{'
            or throw new \InvalidArgumentException('The request body must be a JSON object.');

        // Before building the span objects, which would add to the memory of the decoded arrays.
        self::countSpans($data) > $this->maxSpans and throw LimitExceededException::spans($this->maxSpans);

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

    /**
     * Counts spans without validating the structure; malformed parts count as empty and fail later.
     */
    private static function countSpans(array $data): int
    {
        $count = 0;
        /** @var mixed $resource */
        foreach (self::arrayField($data, 'resourceSpans') as $resource) {
            /** @var mixed $scope */
            foreach (self::arrayField($resource, 'scopeSpans') as $scope) {
                $count += \count(self::arrayField($scope, 'spans'));
            }
        }

        return $count;
    }

    private static function arrayField(mixed $data, string $key): array
    {
        /** @var mixed $value */
        $value = \is_array($data) ? self::field($data, $key) : null;

        return \is_array($value) ? $value : [];
    }

    private static function resourceSpans(mixed $data): ResourceSpans
    {
        $data = self::object($data, 'resourceSpans[]');
        $resource = self::object(self::field($data, 'resource') ?? [], 'resource');

        return new ResourceSpans(
            scopeSpans: \array_map(self::scopeSpans(...), self::list($data, 'scopeSpans')),
            resourceAttributes: self::attributes($resource),
            resourceDroppedAttributesCount: self::uint32($resource, 'droppedAttributesCount'),
            schemaUrl: self::string($data, 'schemaUrl'),
        );
    }

    private static function scopeSpans(mixed $data): ScopeSpans
    {
        $data = self::object($data, 'scopeSpans[]');
        $scope = self::object(self::field($data, 'scope') ?? [], 'scope');

        return new ScopeSpans(
            spans: \array_map(self::span(...), self::list($data, 'spans')),
            scopeName: self::string($scope, 'name'),
            scopeVersion: self::string($scope, 'version'),
            scopeAttributes: self::attributes($scope),
            scopeDroppedAttributesCount: self::uint32($scope, 'droppedAttributesCount'),
            schemaUrl: self::string($data, 'schemaUrl'),
        );
    }

    private static function span(mixed $data): Span
    {
        $data = self::object($data, 'spans[]');
        $status = self::object(self::field($data, 'status') ?? [], 'status');

        return new Span(
            traceId: self::id($data, 'traceId', self::TRACE_ID_BYTES)
                ?? throw new \InvalidArgumentException('Span `traceId` is required and must not be all zeros.'),
            spanId: self::id($data, 'spanId', self::SPAN_ID_BYTES)
                ?? throw new \InvalidArgumentException('Span `spanId` is required and must not be all zeros.'),
            parentSpanId: self::id($data, 'parentSpanId', self::SPAN_ID_BYTES),
            name: self::string($data, 'name'),
            kind: SpanKind::tryFrom(self::enum($data, 'kind', 'SPAN_KIND_', SpanKind::cases())) ?? SpanKind::Unspecified,
            startTimeUnixNano: self::timestamp($data, 'startTimeUnixNano'),
            endTimeUnixNano: self::timestamp($data, 'endTimeUnixNano'),
            attributes: self::attributes($data),
            events: \array_map(self::event(...), self::list($data, 'events')),
            links: \array_map(self::link(...), self::list($data, 'links')),
            status: new SpanStatus(
                StatusCode::tryFrom(self::enum($status, 'code', 'STATUS_CODE_', StatusCode::cases())) ?? StatusCode::Unset,
                self::string($status, 'message'),
            ),
            traceState: self::string($data, 'traceState'),
            flags: self::uint32($data, 'flags'),
            droppedAttributesCount: self::uint32($data, 'droppedAttributesCount'),
            droppedEventsCount: self::uint32($data, 'droppedEventsCount'),
            droppedLinksCount: self::uint32($data, 'droppedLinksCount'),
        );
    }

    private static function event(mixed $data): SpanEvent
    {
        $data = self::object($data, 'events[]');

        return new SpanEvent(
            timeUnixNano: self::timestamp($data, 'timeUnixNano'),
            name: self::string($data, 'name'),
            attributes: self::attributes($data),
            droppedAttributesCount: self::uint32($data, 'droppedAttributesCount'),
        );
    }

    private static function link(mixed $data): SpanLink
    {
        $data = self::object($data, 'links[]');

        return new SpanLink(
            traceId: self::id($data, 'traceId', self::TRACE_ID_BYTES)
                ?? throw new \InvalidArgumentException('Link `traceId` is required and must not be all zeros.'),
            spanId: self::id($data, 'spanId', self::SPAN_ID_BYTES)
                ?? throw new \InvalidArgumentException('Link `spanId` is required and must not be all zeros.'),
            traceState: self::string($data, 'traceState'),
            flags: self::uint32($data, 'flags'),
            attributes: self::attributes($data),
            droppedAttributesCount: self::uint32($data, 'droppedAttributesCount'),
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
            $name = self::field($item, 'key');
            \is_string($name) or throw new \InvalidArgumentException(\sprintf('`%s[].key` must be a string.', $key));
            $result[$name] = self::anyValue(self::field($item, 'value'));
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
            self::has($value, 'stringValue') => self::string($value, 'stringValue'),
            self::has($value, 'boolValue') => self::bool($value, 'boolValue'),
            self::has($value, 'intValue') => self::int64($value, 'intValue'),
            self::has($value, 'doubleValue') => self::double(self::field($value, 'doubleValue')),
            self::has($value, 'bytesValue') => self::string($value, 'bytesValue'),
            self::has($value, 'arrayValue') => \array_map(
                self::anyValue(...),
                self::list(self::object(self::field($value, 'arrayValue') ?? [], 'arrayValue'), 'values'),
            ),
            self::has($value, 'kvlistValue') => new \ArrayObject(self::attributes(
                self::object(self::field($value, 'kvlistValue') ?? [], 'kvlistValue'),
                'values',
            )),
            default => null,
        };
    }

    /**
     * Non-finite doubles stay as their proto3 JSON names because JSON numbers cannot carry them.
     */
    private static function double(mixed $value): float|string
    {
        $double = match (true) {
            \is_float($value) => $value,
            \is_int($value) => (float) $value,
            \in_array($value, ['NaN', 'Infinity', '-Infinity'], true) => $value,
            \is_string($value) && \is_numeric($value) => (float) $value,
            default => throw new \InvalidArgumentException('`doubleValue` must be a number.'),
        };

        // A number like 1e400 overflows to INF while decoding.
        return match (true) {
            !\is_float($double), \is_finite($double) => $double,
            \is_nan($double) => 'NaN',
            default => $double > 0 ? 'Infinity' : '-Infinity',
        };
    }

    /**
     * Accepts lowercase or uppercase hex as the OTLP/JSON spec requires, and base64 as the plain
     * proto3 JSON mapping of `bytes` produces.
     *
     * @param int<1, max> $bytes
     * @return non-empty-string|null Lowercase hex; null when the field is absent, empty or all zeros,
     *         which OpenTelemetry defines as an invalid ID.
     */
    private static function id(array $data, string $key, int $bytes): ?string
    {
        $value = self::string($data, $key);
        if ($value === '') {
            return null;
        }

        $binary = \base64_decode($value, true);
        $hex = match (true) {
            \strlen($value) === $bytes * 2 && \preg_match('/^[0-9a-f]++$/i', $value) === 1 => \strtolower($value),
            $binary !== false && \strlen($binary) === $bytes => \bin2hex($binary),
            default => throw new \InvalidArgumentException(
                \sprintf('`%s` must be a %d-byte hex string.', $key, $bytes),
            ),
        };

        return $hex === '' || \trim($hex, '0') === '' ? null : $hex;
    }

    /**
     * @param list<SpanKind>|list<StatusCode> $cases
     * @return int Zero when the field is absent.
     */
    private static function enum(array $data, string $key, string $prefix, array $cases): int
    {
        /** @var mixed $value */
        $value = self::field($data, $key) ?? 0;
        if (\is_string($value) && \str_starts_with($value, $prefix)) {
            foreach ($cases as $case) {
                if ($prefix . \strtoupper($case->name) === $value) {
                    return $case->value;
                }
            }

            return 0;
        }

        return self::int64($data, $key);
    }

    /**
     * Reads a `fixed64` Unix-nano timestamp. Values above `PHP_INT_MAX` are valid on the wire
     * but not representable, so they are clamped.
     *
     * @return int<0, max>
     */
    private static function timestamp(array $data, string $key): int
    {
        $value = self::integer($data, $key, 0, \PHP_INT_MAX, clamp: true);
        \assert($value >= 0);

        return $value;
    }

    /**
     * @return int<0, 4294967295>
     */
    private static function uint32(array $data, string $key): int
    {
        $value = self::integer($data, $key, 0, self::UINT32_MAX);
        \assert($value >= 0 && $value <= self::UINT32_MAX);

        return $value;
    }

    private static function int64(array $data, string $key): int
    {
        return self::integer($data, $key, \PHP_INT_MIN, \PHP_INT_MAX);
    }

    /**
     * @param bool $clamp Turn a value above `$max`, even one beyond the int64 range, into `$max` instead of failing.
     */
    private static function integer(array $data, string $key, int $min, int $max, bool $clamp = false): int
    {
        /** @var mixed $value */
        $value = self::field($data, $key) ?? 0;

        // Beyond the int64 range a value becomes an infinite float, so the range checks below see it.
        $number = match (true) {
            \is_int($value) => $value,
            \is_string($value) && \preg_match('/^(-?)0*(\d++)$/', $value, $match) === 1
                => self::parseDigits($match[1] === '-', $match[2]),
            \is_float($value) && \is_finite($value) && \floor($value) === $value => match (true) {
                $value >= 9_223_372_036_854_775_808.0 => \INF,
                $value < -9_223_372_036_854_775_808.0 => -\INF,
                default => (int) $value,
            },
            default => throw new \InvalidArgumentException(\sprintf('`%s` must be an integer.', $key)),
        };

        if ($number < $min || ($number > $max && !$clamp)) {
            throw new \InvalidArgumentException(\sprintf('`%s` is out of range.', $key));
        }

        return $number > $max ? $max : (int) $number;
    }

    /**
     * @param string $digits Without leading zeros.
     */
    private static function parseDigits(bool $negative, string $digits): int|float
    {
        $limit = $negative ? '9223372036854775808' : '9223372036854775807';
        $fits = \strlen($digits) < \strlen($limit)
            || (\strlen($digits) === \strlen($limit) && \strcmp($digits, $limit) <= 0);

        return match (true) {
            !$fits => $negative ? -\INF : \INF,
            $negative => (int) ('-' . $digits),
            default => (int) $digits,
        };
    }

    private static function bool(array $data, string $key): bool
    {
        /** @var mixed $value */
        $value = self::field($data, $key);
        \is_bool($value) or throw new \InvalidArgumentException(\sprintf('`%s` must be a boolean.', $key));

        return $value;
    }

    private static function string(array $data, string $key): string
    {
        $value = self::field($data, $key) ?? '';
        \is_string($value) or throw new \InvalidArgumentException(\sprintf('`%s` must be a string.', $key));

        return $value;
    }

    /**
     * @return list<mixed>
     */
    private static function list(array $data, string $key): array
    {
        $value = self::field($data, $key) ?? [];
        \is_array($value) && \array_is_list($value)
            or throw new \InvalidArgumentException(\sprintf('`%s` must be an array.', $key));

        return $value;
    }

    /**
     * Reads a field by its JSON name or by its original proto name: proto3 JSON parsers must accept both.
     */
    private static function field(array $data, string $jsonName): mixed
    {
        return $data[$jsonName] ?? $data[self::protoName($jsonName)] ?? null;
    }

    private static function has(array $data, string $jsonName): bool
    {
        return \array_key_exists($jsonName, $data) || \array_key_exists(self::protoName($jsonName), $data);
    }

    /**
     * `startTimeUnixNano` => `start_time_unix_nano`
     */
    private static function protoName(string $jsonName): string
    {
        return \strtolower((string) \preg_replace('/[A-Z]/', '_$0', $jsonName));
    }

    private static function object(mixed $value, string $name): array
    {
        if (\is_array($value) && ($value === [] || !\array_is_list($value))) {
            return $value;
        }

        throw new \InvalidArgumentException(\sprintf('`%s` must be an object.', $name));
    }
}
