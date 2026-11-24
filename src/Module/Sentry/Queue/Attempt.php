<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Sentry\Queue;

use Buggregator\Trap\Server\Telemetry\Source;
use Buggregator\Trap\Server\Telemetry\Span;
use Buggregator\Trap\Server\Telemetry\Transaction;

/**
 * One run of a queue job by a worker: a transaction or a span with op `queue.process`.
 *
 * Sentry SDKs set the op themselves; Trap gives it to an OTLP `CONSUMER` span with `messaging.system`.
 * The `messaging.*` attributes are read as the Sentry span data conventions define them, malformed values as missing.
 *
 * @internal
 */
final class Attempt
{
    public const OP = 'queue.process';

    /** Attempt numbers above it are malformed. */
    public const MAX_NUMBER = 1_000_000;

    /**
     * @param non-empty-string $id The transaction ID, or `<trace ID>-<span ID>` of a span.
     * @param non-empty-string $project
     * @param non-empty-string $traceId
     * @param non-empty-string|null $messageId `messaging.message.id`: the attempts of one job share it.
     * @param int<1, max>|null $number `messaging.message.retry.count` + 1: 1 for the first run of the message.
     * @param int<0, max>|null $latencyMs `messaging.message.receive.latency` in milliseconds: from publishing
     *        the message to this attempt taking it.
     * @param string|null $transactionId The transaction a span attempt came in; the attempt's own ID for a transaction.
     * @param string $consumer Who took the message, telling apart the jobs of a message delivered to several:
     *        `messaging.consumer.group.name`, else the service of an OTLP span; empty for Sentry data without a group,
     *        whose `server_name` is a host, not a consumer.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $project,
        public readonly string $traceId,
        public readonly ?string $spanId,
        public readonly ?string $parentSpanId,
        public readonly ?string $transactionId,
        public readonly bool $isTransaction,
        public readonly string $name,
        public readonly ?string $messageId,
        public readonly string $queue,
        public readonly string $connection,
        public readonly string $system,
        public readonly string $status,
        public readonly ?int $number,
        public readonly ?int $latencyMs,
        public readonly ?float $start,
        public readonly ?float $end,
        public readonly Source $source,
        public readonly string $consumer = '',
    ) {}

    public static function fromTransaction(Transaction $transaction): ?self
    {
        if (!self::isProcessing($transaction->op, $transaction->attributes)) {
            return null;
        }

        return self::read(
            id: $transaction->id,
            project: $transaction->project,
            traceId: $transaction->traceId,
            spanId: $transaction->spanId,
            parentSpanId: $transaction->parentSpanId,
            transactionId: $transaction->id,
            isTransaction: true,
            name: $transaction->name,
            status: $transaction->status,
            start: $transaction->start,
            end: $transaction->end,
            source: $transaction->source,
            service: $transaction->service,
            attributes: $transaction->attributes,
        );
    }

    public static function fromSpan(Span $span): ?self
    {
        if (!self::isProcessing($span->op, $span->attributes)) {
            return null;
        }

        return self::read(
            id: "{$span->traceId}-{$span->spanId}",
            project: $span->project,
            traceId: $span->traceId,
            spanId: $span->spanId,
            parentSpanId: $span->parentSpanId,
            transactionId: $span->transactionId,
            isTransaction: false,
            name: $span->description,
            status: $span->status,
            start: $span->start,
            end: $span->end,
            source: $span->source,
            service: $span->service,
            attributes: $span->attributes,
        );
    }

    /**
     * A failure as Sentry's failure rate counts it: any status but `ok`, `cancelled` and `unknown`. A missing
     * one is not a failure either.
     */
    public static function isFailedStatus(string $status): bool
    {
        return !\in_array($status, ['', 'ok', 'cancelled', 'unknown'], true);
    }

    /**
     * @return non-empty-string|null A string or an integer ID, trimmed.
     */
    public static function messageId(array $attributes): ?string
    {
        /** @var mixed $value */
        $value = $attributes['messaging.message.id'] ?? null;
        if (!\is_string($value) && !\is_int($value)) {
            return null;
        }

        $value = \trim((string) $value);
        return $value === '' ? null : $value;
    }

    /**
     * Every SDK sends the retries before this run: sentry-laravel `attempts() - 1`, sentry-python Celery
     * `request.retries`. Not a whole number from 0 is malformed.
     *
     * @return int<1, max>|null
     */
    public static function number(array $attributes): ?int
    {
        $retries = self::finite($attributes['messaging.message.retry.count'] ?? null);
        if ($retries === null || $retries < 0.0 || $retries !== \floor($retries) || $retries >= self::MAX_NUMBER) {
            return null;
        }

        return \max(1, (int) $retries + 1);
    }

    /**
     * The spec and most SDKs send milliseconds; sentry-laravel sends seconds (`microtime(true)` minus the
     * publish time it puts into the job payload), told apart by its `messaging.system` `laravel` in Sentry data.
     * An OTLP span is in milliseconds whatever its system: OpenTelemetry defines no such attribute, so a
     * sender of it follows the Sentry spec.
     *
     * @return int<0, max>|null Negative, infinite or not a number is malformed.
     */
    public static function latencyMs(array $attributes, Source $source): ?int
    {
        $latency = self::finite($attributes['messaging.message.receive.latency'] ?? null);
        if ($latency === null || $latency < 0.0) {
            return null;
        }

        $source === Source::Sentry && self::string($attributes, 'messaging.system') === 'laravel'
            and $latency *= 1000.0;

        return $latency >= (float) Span::MAX_DURATION_MS ? Span::MAX_DURATION_MS : \max(0, (int) \round($latency));
    }

    public function failed(): bool
    {
        return self::isFailedStatus($this->status);
    }

    /**
     * @return int<0, max>|null
     */
    public function durationMs(): ?int
    {
        return Span::durationBetween($this->start, $this->end);
    }

    /**
     * OTel consumer spans also receive or settle messages: only `process` (`deliver` before semconv 1.26)
     * runs a job. A span without the operation type is taken as processing, as Sentry SDKs send none.
     */
    private static function isProcessing(string $op, array $attributes): bool
    {
        if ($op !== self::OP) {
            return false;
        }

        /** @var mixed $type */
        $type = $attributes['messaging.operation.type'] ?? $attributes['messaging.operation'] ?? null;
        return !\is_string($type) || \in_array(\strtolower($type), ['process', 'deliver'], true);
    }

    /**
     * @param non-empty-string $id
     * @param non-empty-string $project
     * @param non-empty-string $traceId
     */
    private static function read(
        string $id,
        string $project,
        string $traceId,
        ?string $spanId,
        ?string $parentSpanId,
        ?string $transactionId,
        bool $isTransaction,
        string $name,
        string $status,
        ?float $start,
        ?float $end,
        Source $source,
        ?string $service,
        array $attributes,
    ): self {
        $consumer = self::string($attributes, 'messaging.consumer.group.name');
        $consumer !== '' || $source !== Source::Otel or $consumer = (string) $service;

        return new self(
            id: $id,
            project: $project,
            traceId: $traceId,
            spanId: $spanId,
            parentSpanId: $parentSpanId,
            transactionId: $transactionId,
            isTransaction: $isTransaction,
            name: $name,
            messageId: self::messageId($attributes),
            queue: self::string($attributes, 'messaging.destination.name')
                ?: self::string($attributes, 'messaging.destination'),
            connection: self::string($attributes, 'messaging.destination.connection'),
            system: self::string($attributes, 'messaging.system'),
            status: $status,
            number: self::number($attributes),
            latencyMs: self::latencyMs($attributes, $source),
            start: $start,
            end: $end,
            source: $source,
            consumer: $consumer,
        );
    }

    /**
     * A finite int, float or numeric string.
     */
    private static function finite(mixed $value): ?float
    {
        if (\is_string($value) && \is_numeric(\trim($value))) {
            $value = (float) \trim($value);
        }

        if (\is_int($value)) {
            return (float) $value;
        }

        return \is_float($value) && \is_finite($value) ? $value : null;
    }

    private static function string(array $attributes, string $key): string
    {
        /** @var mixed $value */
        $value = $attributes[$key] ?? null;
        return \is_string($value) ? \trim($value) : '';
    }
}
