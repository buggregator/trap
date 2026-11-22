<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Tracing\Mcp;

use Buggregator\Trap\Module\Tracing\Console\TraceTree;
use Buggregator\Trap\Module\Tracing\Frame\Trace\Attributes;
use Buggregator\Trap\Module\Tracing\Frame\Trace\Span as OtlpSpan;
use Buggregator\Trap\Module\Tracing\Frame\Trace\SpanEvent;
use Buggregator\Trap\Module\Tracing\Frame\Trace\SpanKind;
use Buggregator\Trap\Module\Tracing\Frame\Trace\SpanLink;
use Buggregator\Trap\Module\Tracing\Frame\Trace\SpanStatus;
use Buggregator\Trap\Module\Tracing\Frame\Trace\StatusCode;
use Buggregator\Trap\Server\Telemetry\Source;
use Buggregator\Trap\Server\Telemetry\Span;
use Buggregator\Trap\Server\Telemetry\Transaction;

/**
 * The transactions and spans of a {@see TraceSummary} as OTLP spans, for the {@see TraceTree} the console prints.
 *
 * OTel parts get back the kind, the status, the events and the links the telemetry mapper keeps under `otel.*`
 * attributes. A Sentry span is named `op: description`; its kind is read from the op, and a failure status,
 * such as `internal_error`, is the status message.
 *
 * @internal
 * @psalm-internal Buggregator\Trap\Module\Tracing\Mcp
 *
 * @psalm-import-type AttributeMap from Attributes
 */
final class TraceSpans
{
    /** Attributes the telemetry mapper adds to an OTel span. */
    private const OTEL_KEYS = [
        'otel.kind', 'otel.status_code', 'otel.status_description', 'otel.events', 'otel.links',
        'otel.resource', 'otel.scope',
    ];

    /**
     * @return list<OtlpSpan>
     */
    public static function of(TraceSummary $trace): array
    {
        $spans = [];
        foreach ($trace->transactions as $transaction) {
            $spanId = $transaction->spanId;
            $spanId === null || $spanId === '' and $spanId = $transaction->id;
            $spans[] = self::span(
                $transaction,
                $spanId,
                $transaction->name === '' ? $transaction->op : $transaction->name,
            );
        }

        foreach ($trace->spans as $span) {
            $name = match (true) {
                $span->source === Source::Otel, $span->op === '' => $span->description,
                $span->description === '' => $span->op,
                default => "$span->op: $span->description",
            };
            $spans[] = self::span($span, $span->spanId, $name);
        }

        return $spans;
    }

    /**
     * @return list<array<array-key, mixed>> The distinct resource attributes of the OTel parts.
     */
    public static function resources(TraceSummary $trace): array
    {
        $result = [];
        foreach ([...$trace->transactions, ...$trace->spans] as $part) {
            /** @var mixed $resource */
            $resource = $part->attributes['otel.resource'] ?? null;
            \is_array($resource) && $resource !== [] && !\in_array($resource, $result, true) and $result[] = $resource;
        }

        return $result;
    }

    /**
     * @param non-empty-string $spanId
     */
    private static function span(Span|Transaction $part, string $spanId, string $name): OtlpSpan
    {
        // OTel values as the decoder gives them, Sentry data as JSON gives it: printed as JSON either way.
        /** @var AttributeMap $attributes */
        $attributes = $part->attributes;
        $otel = $part->source === Source::Otel;
        $start = self::nanos($part->start ?? $part->end);
        $end = self::nanos($part->end ?? $part->start);
        $parentId = $part->parentSpanId;

        return new OtlpSpan(
            traceId: $part->traceId,
            spanId: $spanId,
            parentSpanId: $parentId === null || $parentId === '' ? null : $parentId,
            name: $name,
            kind: $otel ? self::otelKind($attributes['otel.kind'] ?? null) : self::sentryKind($part->op),
            startTimeUnixNano: $start,
            endTimeUnixNano: \max($start, $end),
            attributes: $otel ? \array_diff_key($attributes, \array_flip(self::OTEL_KEYS)) : $attributes,
            events: $otel ? self::events($attributes['otel.events'] ?? null) : [],
            links: $otel ? self::links($attributes['otel.links'] ?? null) : [],
            status: $otel ? self::otelStatus($attributes) : self::sentryStatus($part->status),
        );
    }

    private static function otelKind(mixed $kind): SpanKind
    {
        return match ($kind) {
            'server' => SpanKind::Server,
            'client' => SpanKind::Client,
            'producer' => SpanKind::Producer,
            'consumer' => SpanKind::Consumer,
            default => SpanKind::Internal,
        };
    }

    private static function sentryKind(string $op): SpanKind
    {
        return match (true) {
            $op === 'http.server' => SpanKind::Server,
            $op === 'http.client' => SpanKind::Client,
            \str_starts_with($op, 'queue.publish') => SpanKind::Producer,
            \str_starts_with($op, 'queue.process') => SpanKind::Consumer,
            default => SpanKind::Internal,
        };
    }

    private static function otelStatus(array $attributes): SpanStatus
    {
        /** @var mixed $message */
        $message = $attributes['otel.status_description'] ?? '';

        return new SpanStatus(
            match ($attributes['otel.status_code'] ?? null) {
                'ok' => StatusCode::Ok,
                'error' => StatusCode::Error,
                default => StatusCode::Unset,
            },
            \is_string($message) ? $message : '',
        );
    }

    private static function sentryStatus(string $status): SpanStatus
    {
        return match (true) {
            $status === 'ok' => new SpanStatus(StatusCode::Ok),
            Span::isErrorStatus($status) => new SpanStatus(StatusCode::Error, $status),
            default => new SpanStatus(),
        };
    }

    /**
     * @return list<SpanEvent>
     */
    private static function events(mixed $events): array
    {
        $result = [];
        foreach (\is_array($events) ? $events : [] as $event) {
            if (!\is_array($event)) {
                continue;
            }

            /** @var mixed $name */
            $name = $event['name'] ?? '';
            /** @var AttributeMap $attributes */
            $attributes = \is_array($event['attributes'] ?? null) ? $event['attributes'] : [];
            $result[] = new SpanEvent(
                self::isoNanos($event['time'] ?? null),
                \is_string($name) ? $name : '',
                $attributes,
            );
        }

        return $result;
    }

    /**
     * @return list<SpanLink>
     */
    private static function links(mixed $links): array
    {
        $result = [];
        /** @var mixed $link */
        foreach (\is_array($links) ? $links : [] as $link) {
            /** @var mixed $traceId */
            $traceId = \is_array($link) ? $link['trace_id'] ?? null : null;
            /** @var mixed $spanId */
            $spanId = \is_array($link) ? $link['span_id'] ?? null : null;
            \is_string($traceId) && $traceId !== '' && \is_string($spanId) && $spanId !== ''
                and $result[] = new SpanLink($traceId, $spanId);
        }

        return $result;
    }

    /**
     * @param float|null $seconds Unix seconds.
     */
    private static function nanos(?float $seconds): int
    {
        return $seconds === null || !\is_finite($seconds) || $seconds <= 0.0 || $seconds >= 9.2e9
            ? 0
            : (int) \round($seconds * 1e9);
    }

    /**
     * @return int Unix nanoseconds of a time the telemetry mapper writes, as `2024-01-01T10:00:00.123456Z`;
     *         0 for others.
     */
    private static function isoNanos(mixed $time): int
    {
        $date = \is_string($time)
            ? \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.u\Z', $time, new \DateTimeZone('UTC'))
            : false;

        return $date === false ? 0 : (int) $date->format('U') * 1_000_000_000 + (int) $date->format('u') * 1000;
    }
}
