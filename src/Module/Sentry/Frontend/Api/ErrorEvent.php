<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Sentry\Frontend\Api;

use Buggregator\Trap\Module\Sentry\Telemetry\ItemReader;
use Buggregator\Trap\Server\Event\Event;

/**
 * A Sentry error or message event of the event feed, as the exception lists group and filter it.
 *
 * @internal
 */
final class ErrorEvent
{
    /**
     * @param non-empty-string $uuid The feed event, which the UI opens.
     * @param non-empty-string $project
     * @param non-empty-string $fingerprint 16 hex digits: the first exception type and value, or the message.
     */
    private function __construct(
        public readonly string $uuid,
        public readonly string $project,
        public readonly float $time,
        public readonly string $fingerprint,
        public readonly string $type,
        public readonly string $value,
        public readonly string $level,
        public readonly ?bool $handled,
        public readonly ?string $traceId,
        public readonly string $eventId,
        public readonly string $transaction,
    ) {}

    /**
     * @return self|null Null for an event that is not a Sentry error: another type, a transaction or a feedback.
     */
    public static function fromEvent(Event $event): ?self
    {
        $payload = $event->payload;
        if ($event->type !== 'sentry'
            || !\is_array($payload)
            || ItemReader::isTransaction($payload)
            || ($payload['type'] ?? null) === 'feedback'
        ) {
            return null;
        }

        $exception = ItemReader::exceptions($payload)[0] ?? null;
        $type = self::string($exception['type'] ?? null);
        $value = self::string($exception['value'] ?? null);
        /** @var mixed $mechanism */
        $mechanism = $exception['mechanism'] ?? null;
        /** @var mixed $handled */
        $handled = \is_array($mechanism) ? $mechanism['handled'] ?? null : null;
        $handled = \is_bool($handled) ? $handled : null;

        if ($exception === null) {
            $value = self::message($payload);
            $source = 'message|' . \substr($value, 0, 100);
        } else {
            $source = $type . '|' . \substr($value, 0, 100);
        }

        /** @var mixed $trace */
        $trace = $payload['contexts']['trace'] ?? null;
        $traceId = \is_array($trace) ? self::string($trace['trace_id'] ?? null) : '';
        $level = self::string($payload['level'] ?? null);

        return new self(
            uuid: $event->uuid,
            project: $event->projectKey(),
            time: $event->timestamp,
            fingerprint: \substr(\hash('sha256', $source), 0, 16),
            type: $type,
            value: $value,
            level: $level === '' ? 'error' : $level,
            handled: $handled,
            traceId: $traceId === '' ? null : $traceId,
            eventId: self::string($payload['event_id'] ?? null),
            transaction: self::string($payload['transaction'] ?? null),
        );
    }

    /**
     * `message` as a string or `{"formatted": ..., "message": ...}`, else `logentry`.
     */
    private static function message(array $payload): string
    {
        foreach (['message', 'logentry'] as $key) {
            /** @var mixed $message */
            $message = $payload[$key] ?? null;
            if (\is_array($message)) {
                $message = self::string($message['formatted'] ?? null) ?: self::string($message['message'] ?? null);
            }
            if (\is_string($message) && $message !== '') {
                return $message;
            }
        }

        return '';
    }

    private static function string(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }
}
