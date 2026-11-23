<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Sentry\Telemetry;

use Buggregator\Trap\Server\Telemetry\Feedback;
use Buggregator\Trap\Support\Uuid;

/**
 * Reads user feedback from a Sentry envelope item, as buggregator/server does:
 *
 * - `feedback`, of the User Feedback API: an event whose `contexts.feedback` holds `message`, `contact_email`,
 *   `name`, `url` and `associated_event_id`, the error it is about;
 * - `user_report`, the legacy one: `{"event_id", "name", "email", "comments"}`.
 *
 * @internal
 */
final class FeedbackReader
{
    /**
     * @param non-empty-string $project
     * @param 'feedback'|'user_report' $type
     * @param non-empty-string|null $envelopeEventId The envelope `event_id`: of the feedback event when its payload
     *        has none.
     */
    public static function read(
        string $project,
        string $type,
        array $payload,
        ?string $envelopeEventId,
        float $time,
    ): Feedback {
        if ($type === Feedback::SOURCE_USER_REPORT) {
            return new Feedback(
                id: Uuid::generate(),
                project: $project,
                eventId: self::string($payload['event_id'] ?? null),
                feedbackId: '',
                name: self::string($payload['name'] ?? null),
                email: self::string($payload['email'] ?? null),
                message: self::string($payload['comments'] ?? null),
                url: '',
                source: Feedback::SOURCE_USER_REPORT,
                time: $time,
                payload: $payload,
            );
        }

        $contexts = self::object($payload['contexts'] ?? null);
        $form = self::object($contexts['feedback'] ?? null);
        $user = self::object($payload['user'] ?? null);

        return new Feedback(
            id: Uuid::generate(),
            project: $project,
            eventId: self::string($form['associated_event_id'] ?? null),
            feedbackId: self::first($payload['event_id'] ?? null, $envelopeEventId),
            name: self::first($form['name'] ?? null, $user['name'] ?? null, $user['username'] ?? null),
            email: self::first($form['contact_email'] ?? null, $user['email'] ?? null),
            message: self::string($form['message'] ?? null),
            url: self::string($form['url'] ?? null),
            source: Feedback::SOURCE_FEEDBACK,
            time: $time,
            payload: $payload,
        );
    }

    private static function first(mixed ...$values): string
    {
        /** @var mixed $value */
        foreach ($values as $value) {
            $string = self::string($value);
            if ($string !== '') {
                return $string;
            }
        }

        return '';
    }

    private static function object(mixed $value): array
    {
        return \is_array($value) ? $value : [];
    }

    private static function string(mixed $value): string
    {
        return \is_string($value) ? \trim($value) : '';
    }
}
