<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp\View;

use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\Record;
use Buggregator\Trap\Support\Json;

/**
 * Summaries of the common event types, read from the payloads the web UI gets.
 *
 * @internal
 */
final class PayloadSummary implements EventView
{
    /** Characters of a summary, the ellipsis excluded. */
    public const MAX_LENGTH = 160;

    #[\Override]
    public function summary(Event $event): ?string
    {
        $payload = $event->payload;
        // Ray keeps its payloads in an object; a profile is too large to serialize for a summary
        if ($payload instanceof \JsonSerializable) {
            if ($event->type !== 'ray') {
                return null;
            }

            /** @var mixed $payload */
            $payload = $payload->jsonSerialize();
            \is_array($payload) or $payload = [];
        }

        $summary = match ($event->type) {
            'var-dump' => self::dump($payload),
            'monolog' => self::log($payload),
            'sentry' => self::sentry($payload),
            'smtp' => self::mail($payload),
            'http-dump' => self::http($payload),
            'ray' => self::ray($payload),
            default => '',
        };
        $summary = self::shorten($summary);

        return $summary === '' ? null : $summary;
    }

    #[\Override]
    public function text(Record $record): ?string
    {
        return null;
    }

    /**
     * One line of text: an HTML dump loses its markup, a structure becomes JSON.
     */
    private static function plain(mixed $value): string
    {
        $text = match (true) {
            \is_string($value) => \html_entity_decode(
                \strip_tags((string) \preg_replace('~<(script|style)\b.*?</\1>~si', '', $value)),
                \ENT_QUOTES | \ENT_HTML5,
            ),
            \is_bool($value) => $value ? 'true' : 'false',
            $value === null => '',
            \is_scalar($value) => (string) $value,
            default => self::json($value),
        };

        return \trim((string) \preg_replace('/\s+/u', ' ', $text));
    }

    private static function shorten(string $text): string
    {
        $text = \trim((string) \preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text));

        return \mb_strlen($text) > self::MAX_LENGTH ? \mb_substr($text, 0, self::MAX_LENGTH) . '…' : $text;
    }

    private static function json(mixed $value): string
    {
        try {
            return Json::encode($value);
        } catch (\JsonException) {
            return '';
        }
    }

    private static function labeled(mixed $label, string $text): string
    {
        $label = self::plain($label);

        return $label === '' || $text === '' ? $label . $text : "$label: $text";
    }

    private static function dump(array $payload): string
    {
        /** @var mixed $dump */
        $dump = $payload['payload'] ?? null;
        if (!\is_array($dump)) {
            return '';
        }

        return self::labeled($dump['label'] ?? null, self::plain($dump['value'] ?? null));
    }

    private static function log(array $payload): string
    {
        $level = self::plain($payload['level_name'] ?? null);
        $channel = self::plain($payload['channel'] ?? null);
        $message = self::plain($payload['message'] ?? null);

        return \trim($level . ' ' . self::labeled($channel, $message));
    }

    private static function sentry(array $payload): string
    {
        /** @var mixed $exceptions */
        $exceptions = $payload['exception']['values'] ?? $payload['exception'] ?? null;
        if (\is_array($exceptions) && $exceptions !== []) {
            // The last one is the exception that was thrown, the others are its previous ones
            /** @var mixed $exception */
            $exception = \end($exceptions);
            if (\is_array($exception)) {
                return self::labeled($exception['type'] ?? null, self::plain($exception['value'] ?? null));
            }
        }

        /** @var mixed $message */
        $message = $payload['message'] ?? $payload['logentry'] ?? null;
        if (\is_array($message)) {
            /** @var mixed $message */
            $message = $message['formatted'] ?? $message['message'] ?? null;
        }
        $message = self::plain($message);
        if ($message !== '') {
            return $message;
        }

        return self::labeled($payload['type'] ?? null, self::plain($payload['transaction'] ?? null));
    }

    private static function mail(array $payload): string
    {
        $subject = self::plain($payload['subject'] ?? null);
        $from = self::contacts($payload['from'] ?? null);
        $to = self::contacts($payload['to'] ?? null);

        return \trim(\implode(' ', [
            $subject === '' ? '(no subject)' : $subject,
            $from === '' ? '' : "from $from",
            $to === '' ? '' : "to $to",
        ]));
    }

    /**
     * @param mixed $contacts Objects or arrays with `name` and `email`.
     */
    private static function contacts(mixed $contacts): string
    {
        $result = [];
        /** @var mixed $contact */
        foreach (\is_iterable($contacts) ? $contacts : [] as $contact) {
            $fields = \is_object($contact) ? \get_object_vars($contact) : (array) $contact;
            $email = self::plain($fields['email'] ?? null);
            $result[] = $email === '' ? self::plain($fields['name'] ?? null) : $email;
        }

        return \implode(', ', \array_filter($result));
    }

    private static function http(array $payload): string
    {
        /** @var mixed $request */
        $request = $payload['request'] ?? null;
        if (!\is_array($request)) {
            return '';
        }

        return \trim(self::plain($request['method'] ?? null) . ' /' . self::plain($request['uri'] ?? null));
    }

    private static function ray(array $payload): string
    {
        $parts = [];
        foreach (\is_array($payload['payloads'] ?? null) ? $payload['payloads'] : [] as $item) {
            if (!\is_array($item)) {
                continue;
            }

            /** @var mixed $content */
            $content = $item['content'] ?? null;
            $type = self::plain($item['type'] ?? null);
            $text = match (true) {
                !\is_array($content) => self::plain($content),
                $type === 'log' => \implode(', ', \array_map(self::plain(...), (array) ($content['values'] ?? []))),
                $type === 'custom' => self::labeled($content['label'] ?? null, self::plain($content['content'] ?? null)),
                $type === 'exception' => self::labeled($content['class'] ?? null, self::plain($content['message'] ?? null)),
                default => self::plain($content),
            };
            $parts[] = self::labeled($type, $text);
        }

        return \implode('; ', $parts);
    }
}
