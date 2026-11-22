<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Sentry\Telemetry;

use Buggregator\Trap\Server\Telemetry\Batch;
use Buggregator\Trap\Server\Telemetry\Log;
use Buggregator\Trap\Server\Telemetry\Metric;
use Buggregator\Trap\Server\Telemetry\Peer;
use Buggregator\Trap\Server\Telemetry\Source;
use Buggregator\Trap\Server\Telemetry\Span;
use Buggregator\Trap\Server\Telemetry\Transaction;
use Buggregator\Trap\Support\Json;
use Buggregator\Trap\Support\Uuid;

/**
 * Reads the telemetry of Sentry envelope items into a {@see Batch}: transactions with their spans,
 * v2 spans (`span`, `spans`), logs (`log`) and trace metrics (`trace_metric`).
 *
 * Records it cannot read, like a transaction without a trace ID, are skipped one by one.
 *
 * @internal
 */
final class ItemReader
{
    /** Item types that never make a feed event. */
    public const TELEMETRY_ITEMS = ['transaction', 'span', 'spans', 'log', 'trace_metric'];

    /**
     * Reads an item of a telemetry type into the batch; other items are ignored.
     *
     * @param non-empty-string|null $eventId The envelope `event_id`, for SDKs that send it there only.
     * @return bool Whether the item had a telemetry type; false for a transaction without a trace ID too.
     */
    public static function read(Batch $batch, string $type, mixed $payload, ?string $eventId = null): bool
    {
        if (!\is_array($payload)) {
            return \in_array($type, self::TELEMETRY_ITEMS, true);
        }

        match ($type) {
            'span', 'spans' => self::spans($batch, self::records($payload)),
            'log' => self::logs($batch, self::records($payload)),
            'trace_metric' => self::metrics($batch, self::records($payload)),
            default => null,
        };

        return $type === 'transaction'
            ? self::transaction($batch, $payload, $eventId)
            : \in_array($type, self::TELEMETRY_ITEMS, true);
    }

    /**
     * Whether a payload of the `store` endpoint is a transaction, not an error: it has no exception, and
     * either the `transaction` type or spans and a start. An exception makes an error of any type, as in
     * buggregator/server.
     */
    public static function isTransaction(array $payload): bool
    {
        if (self::exceptions($payload) !== []) {
            return false;
        }

        return ($payload['type'] ?? null) === 'transaction'
            || \array_key_exists('spans', $payload) && \array_key_exists('start_timestamp', $payload);
    }

    /**
     * @return list<array> The exceptions of an event, from `{"values": [...]}` or a bare list.
     */
    public static function exceptions(array $event): array
    {
        /** @var mixed $exception */
        $exception = $event['exception'] ?? null;
        /** @var mixed $values */
        $values = \is_array($exception) && \array_key_exists('values', $exception) ? $exception['values'] : $exception;

        return \is_array($values) ? self::arrays($values) : [];
    }

    /**
     * Epoch seconds as a number or a numeric string, or an RFC 3339 string.
     */
    public static function time(mixed $value): ?float
    {
        if (\is_int($value) || \is_float($value)) {
            return \is_finite((float) $value) ? (float) $value : null;
        }

        if (!\is_string($value) || $value === '') {
            return null;
        }

        if (\is_numeric($value)) {
            return \is_finite((float) $value) ? (float) $value : null;
        }

        $date = \date_create_immutable($value);
        return $date === false ? null : (float) $date->format('U.u');
    }

    /**
     * @param non-empty-string|null $eventId The ID when the payload has none.
     * @return bool False for a transaction without a trace ID, which is not read.
     */
    public static function transaction(Batch $batch, array $payload, ?string $eventId = null): bool
    {
        $context = self::map($payload, 'contexts');
        $trace = self::map($context, 'trace');
        $spans = self::arrays(self::list($payload, 'spans'));

        $traceId = self::string($trace, 'trace_id');
        foreach ($spans as $span) {
            $traceId ??= self::string($span, 'trace_id');
        }
        if ($traceId === null) {
            return false;
        }

        $data = self::map($trace, 'data');
        $service = self::string($data, 'service.name')
            ?? self::string($payload, 'server_name')
            ?? self::string(self::map($payload, 'sdk'), 'name');
        $id = self::string($payload, 'event_id') ?? $eventId ?? Uuid::generate();

        $batch->transactions[] = new Transaction(
            id: $id,
            project: $batch->project,
            traceId: $traceId,
            spanId: self::string($trace, 'span_id'),
            parentSpanId: self::string($trace, 'parent_span_id'),
            name: self::string($payload, 'transaction') ?? '',
            op: self::string($payload, 'op') ?? self::string($trace, 'op') ?? '',
            status: self::string($payload, 'status') ?? self::string($trace, 'status') ?? '',
            start: self::time($payload['start_timestamp'] ?? null),
            end: self::time($payload['timestamp'] ?? null),
            source: Source::Sentry,
            service: $service,
            attributes: $data,
            environment: self::string($payload, 'environment'),
            release: self::string($payload, 'release'),
        );

        foreach ($spans as $span) {
            $spanId = self::string($span, 'span_id');
            if ($spanId === null) {
                continue;
            }

            $attributes = self::map($span, 'data');
            $op = self::string($span, 'op') ?? '';
            $description = self::string($span, 'description') ?? '';
            $batch->spans[] = new Span(
                project: $batch->project,
                traceId: $traceId,
                spanId: $spanId,
                parentSpanId: self::string($span, 'parent_span_id'),
                transactionId: $id,
                op: $op,
                description: $description,
                status: self::string($span, 'status') ?? '',
                start: self::time($span['start_timestamp'] ?? null),
                end: self::time($span['timestamp'] ?? null),
                source: Source::Sentry,
                service: self::string($attributes, 'service.name') ?? $service,
                peer: Peer::classify($op, $attributes, $description),
                attributes: $attributes,
            );
        }

        return true;
    }

    /**
     * Span v2 items. A segment span, the root of a streamed trace, becomes a transaction.
     *
     * @param list<array> $spans
     */
    private static function spans(Batch $batch, array $spans): void
    {
        foreach ($spans as $span) {
            $traceId = self::string($span, 'trace_id');
            $spanId = self::string($span, 'span_id');
            if ($traceId === null || $spanId === null) {
                continue;
            }

            $attributes = self::map($span, 'data') + self::plainAttributes(self::map($span, 'attributes'));
            $op = self::string($span, 'op') ?? self::string($attributes, 'sentry.op') ?? '';
            $description = self::string($span, 'description') ?? self::string($span, 'name') ?? '';
            $status = self::string($span, 'status') ?? '';
            $start = self::time($span['start_timestamp'] ?? null);
            $end = self::time($span['timestamp'] ?? $span['end_timestamp'] ?? null);
            $service = self::string($attributes, 'service.name');

            if (($span['is_segment'] ?? false) === true) {
                $batch->transactions[] = new Transaction(
                    id: "$traceId-$spanId",
                    project: $batch->project,
                    traceId: $traceId,
                    spanId: $spanId,
                    parentSpanId: self::string($span, 'parent_span_id'),
                    name: $description,
                    op: $op,
                    status: $status,
                    start: $start,
                    end: $end,
                    source: Source::Sentry,
                    service: $service,
                    attributes: $attributes,
                    environment: self::string($attributes, 'sentry.environment'),
                    release: self::string($attributes, 'sentry.release'),
                );
                continue;
            }

            $batch->spans[] = new Span(
                project: $batch->project,
                traceId: $traceId,
                spanId: $spanId,
                parentSpanId: self::string($span, 'parent_span_id'),
                transactionId: null,
                op: $op,
                description: $description,
                status: $status,
                start: $start,
                end: $end,
                source: Source::Sentry,
                service: $service,
                peer: Peer::classify($op, $attributes, $description),
                attributes: $attributes,
            );
        }
    }

    /**
     * @param list<array> $logs
     */
    private static function logs(Batch $batch, array $logs): void
    {
        foreach ($logs as $log) {
            $attributes = self::map($log, 'attributes');
            $plain = self::plainAttributes($attributes);
            /** @var mixed $severity */
            $severity = $log['severity_number'] ?? null;
            /** @var mixed $body */
            $body = $log['body'] ?? '';

            $batch->logs[] = new Log(
                id: Uuid::generate(),
                project: $batch->project,
                level: self::string($log, 'level') ?? 'info',
                body: \is_string($body) ? $body : (string) \json_encode($body, \JSON_UNESCAPED_UNICODE),
                time: self::time($log['timestamp'] ?? null),
                source: Source::Sentry,
                traceId: self::string($log, 'trace_id'),
                spanId: self::string($log, 'span_id'),
                severityNumber: \is_int($severity) && $severity > 0 ? $severity : null,
                service: self::string($plain, 'service.name')
                    ?? self::string($plain, 'server.address')
                    ?? self::string($plain, 'sentry.server.address'),
                attributes: $attributes,
            );
        }
    }

    /**
     * @param list<array> $metrics
     */
    private static function metrics(Batch $batch, array $metrics): void
    {
        foreach ($metrics as $metric) {
            $name = self::string($metric, 'name');
            $value = $metric['value'] ?? null;
            if ($name === null || !\is_numeric($value) || !\is_finite((float) $value)) {
                continue;
            }

            $batch->metrics[] = new Metric(
                id: Uuid::generate(),
                project: $batch->project,
                name: $name,
                type: self::string($metric, 'type') ?? 'gauge',
                value: (float) $value,
                time: self::time($metric['timestamp'] ?? null),
                source: Source::Sentry,
                unit: self::string($metric, 'unit') ?? '',
                traceId: self::string($metric, 'trace_id'),
                spanId: self::string($metric, 'span_id'),
                attributes: self::map($metric, 'attributes'),
            );
        }
    }

    /**
     * The records of a `log`, `trace_metric` or `spans` item: `{"items": [...]}` or a bare list.
     *
     * @return list<array>
     */
    private static function records(array $payload): array
    {
        $items = match (true) {
            \array_is_list($payload) => $payload,
            isset($payload['span_id']) => [$payload],
            default => self::list($payload, 'items'),
        };

        return self::arrays($items);
    }

    /**
     * @return list<array> The arrays among the values.
     */
    private static function arrays(array $values): array
    {
        $arrays = [];
        /** @var mixed $value */
        foreach ($values as $value) {
            \is_array($value) and $arrays[] = $value;
        }

        return $arrays;
    }

    /**
     * Typed attributes `{"key": {"type": "string", "value": "..."}}` as plain `{"key": "..."}`.
     */
    private static function plainAttributes(array $attributes): array
    {
        $result = [];
        /** @var mixed $value */
        foreach ($attributes as $key => $value) {
            /** @psalm-suppress MixedAssignment */
            $result[$key] = \is_array($value) && \array_key_exists('value', $value) ? $value['value'] : $value;
        }

        return $result;
    }

    /**
     * @return non-empty-string|null
     */
    private static function string(array $data, string $key): ?string
    {
        /** @var mixed $value */
        $value = $data[$key] ?? null;
        return \is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The map with non-finite numbers as their names, `NaN`, `Infinity`, `-Infinity`: JSON cannot carry them,
     * and the answer of every page with the record would fail.
     */
    private static function map(array $data, string $key): array
    {
        /** @var mixed $value */
        $value = $data[$key] ?? null;
        return \is_array($value) ? (array) Json::finite($value) : [];
    }

    private static function list(array $data, string $key): array
    {
        /** @var mixed $value */
        $value = $data[$key] ?? null;
        return \is_array($value) && \array_is_list($value) ? $value : [];
    }
}
