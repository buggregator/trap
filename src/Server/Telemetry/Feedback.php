<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Telemetry;

/**
 * What a person wrote in the Sentry feedback widget (a `feedback` item), or a legacy `user_report`.
 *
 * @internal
 */
final class Feedback
{
    public const SOURCE_FEEDBACK = 'feedback';
    public const SOURCE_USER_REPORT = 'user_report';

    /**
     * @param non-empty-string $id
     * @param non-empty-string $project
     * @param string $eventId The Sentry `event_id` of the error it is about, as sent; empty for feedback on its own.
     * @param string $feedbackId The `event_id` of the feedback event itself; empty for a `user_report`.
     * @param self::SOURCE_* $source
     * @param float $time Unix seconds when Trap received it.
     * @param array<array-key, mixed> $payload The item as sent.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $project,
        public readonly string $eventId,
        public readonly string $feedbackId,
        public readonly string $name,
        public readonly string $email,
        public readonly string $message,
        public readonly string $url,
        public readonly string $source,
        public readonly float $time,
        public readonly array $payload = [],
    ) {}

    /**
     * An event ID in lower case without dashes: SDKs send both forms of one ID.
     */
    public static function normalizeEventId(string $id): string
    {
        return \strtolower(\str_replace('-', '', \trim($id)));
    }

    /**
     * Whether it is about the error of this Sentry event ID, in either form.
     */
    public function isAbout(string $eventId): bool
    {
        $eventId = self::normalizeEventId($eventId);

        return $eventId !== '' && self::normalizeEventId($this->eventId) === $eventId;
    }
}
