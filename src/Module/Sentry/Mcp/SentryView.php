<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Sentry\Mcp;

use Buggregator\Trap\Module\Mcp\View\EventFields;
use Buggregator\Trap\Module\Mcp\View\EventView;
use Buggregator\Trap\Module\Mcp\View\PayloadSummary;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\Record;

/**
 * Sentry events for the MCP tools: the summary, the fields, and the text of {@see SentryText} in place of
 * the console one.
 *
 * @internal
 */
final class SentryView implements EventView, EventFields
{
    public const TYPE = 'sentry';

    #[\Override]
    public function summary(Event $event): ?string
    {
        $payload = self::payload($event);
        if ($payload === null) {
            return null;
        }

        $summary = PayloadSummary::shorten(self::summaryOf($payload));

        return $summary === '' ? null : $summary;
    }

    /**
     * @return string|null Null for an event that is not a Sentry one. For an event {@see SentryText} fails on, the
     *         summary and the error, not left to the next views: they show the payload with its secrets.
     */
    #[\Override]
    public function text(Record $record): ?string
    {
        if (self::payload($record->event) === null) {
            return null;
        }

        try {
            return SentryText::render($record);
        } catch (\Throwable $e) {
            try {
                $summary = $this->summary($record->event);
            } catch (\Throwable) {
                $summary = null;
            }

            return ($summary === null ? '' : "$summary\n\n")
                . 'The event could not be rendered: ' . \get_debug_type($e) . "\n";
        }
    }

    #[\Override]
    public function fieldNames(): array
    {
        return [self::TYPE => ['level', 'exception', 'environment', 'release', 'transaction', 'server_name', 'status']];
    }

    #[\Override]
    public function fields(Event $event): ?array
    {
        $payload = self::payload($event);
        if ($payload === null) {
            return null;
        }

        $types = [];
        foreach (SentryText::exceptions($payload) as $exception) {
            $types[] = PayloadSummary::plain($exception['type'] ?? null);
        }

        /** @var mixed $response */
        $response = $payload['contexts']['response'] ?? null;
        /** @var mixed $status */
        $status = \is_array($response) ? $response['status_code'] ?? null : null;

        return [
            'level' => PayloadSummary::plain($payload['level'] ?? null),
            'exception' => \array_values(\array_filter($types)),
            'environment' => PayloadSummary::plain($payload['environment'] ?? null),
            'release' => PayloadSummary::plain($payload['release'] ?? null),
            'transaction' => PayloadSummary::plain($payload['transaction'] ?? null),
            'server_name' => PayloadSummary::plain($payload['server_name'] ?? null),
            'status' => \is_int($status) ? $status : '',
        ];
    }

    private static function payload(Event $event): ?array
    {
        return $event->type === self::TYPE && \is_array($event->payload) ? $event->payload : null;
    }

    /**
     * The thrown exception, else the message, else the event type and transaction.
     */
    private static function summaryOf(array $payload): string
    {
        $exceptions = SentryText::exceptions($payload);
        if ($exceptions !== []) {
            $exception = \end($exceptions);

            return self::labeled($exception['type'] ?? null, PayloadSummary::plain($exception['value'] ?? null));
        }

        $message = PayloadSummary::plain(SentryText::message($payload));
        if ($message !== '') {
            return $message;
        }

        return self::labeled($payload['type'] ?? null, PayloadSummary::plain($payload['transaction'] ?? null));
    }

    private static function labeled(mixed $label, string $text): string
    {
        $label = PayloadSummary::plain($label);

        return $label === '' || $text === '' ? $label . $text : "$label: $text";
    }
}
