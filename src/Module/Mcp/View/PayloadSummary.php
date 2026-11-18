<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp\View;

use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\Record;
use Buggregator\Trap\Support\Json;

/**
 * Summaries and fields of the common event types, read from the payloads the web UI gets.
 *
 * @internal
 */
final class PayloadSummary implements EventView, EventFields
{
    /** Characters of a summary, the ellipsis excluded. */
    public const MAX_LENGTH = 160;

    private const FIELDS = [
        'var-dump' => ['label', 'value_type', 'language', 'file'],
        'monolog' => ['level', 'channel'],
        'smtp' => ['subject', 'from', 'to', 'cc', 'bcc'],
        'http-dump' => ['method', 'path', 'host'],
        'ray' => ['payload_type', 'exception', 'label', 'color'],
    ];

    /**
     * One line of text: an HTML dump loses its markup, a structure becomes JSON.
     */
    public static function plain(mixed $value): string
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

    /**
     * Without control characters, cut to {@see self::MAX_LENGTH}: a summary of any view.
     */
    public static function shorten(string $text): string
    {
        $text = \trim((string) \preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text));

        return \mb_strlen($text) > self::MAX_LENGTH ? \mb_substr($text, 0, self::MAX_LENGTH) . '…' : $text;
    }

    #[\Override]
    public function summary(Event $event): ?string
    {
        $payload = self::payload($event);
        if ($payload === null) {
            return null;
        }

        $summary = match ($event->type) {
            'var-dump' => self::dump($payload),
            'monolog' => self::log($payload),
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

    #[\Override]
    public function fieldNames(): array
    {
        return self::FIELDS;
    }

    #[\Override]
    public function fields(Event $event): ?array
    {
        $payload = self::payload($event);
        if ($payload === null || !isset(self::FIELDS[$event->type])) {
            return null;
        }

        return match ($event->type) {
            'var-dump' => self::dumpFields($payload),
            'monolog' => ['level' => self::plain($payload['level_name'] ?? null), 'channel' => self::plain($payload['channel'] ?? null)],
            'smtp' => [
                'subject' => self::plain($payload['subject'] ?? null),
                'from' => self::contactList($payload['from'] ?? null),
                'to' => self::contactList($payload['to'] ?? null),
                'cc' => self::contactList($payload['cc'] ?? null),
                'bcc' => self::contactList($payload['bcc'] ?? null),
            ],
            'http-dump' => self::httpFields($payload),
            default => self::rayFields($payload),
        };
    }

    /**
     * Ray keeps its payloads in an object; a profile is too large to serialize.
     */
    private static function payload(Event $event): ?array
    {
        $payload = $event->payload;
        if (!$payload instanceof \JsonSerializable) {
            return $payload;
        }

        if ($event->type !== 'ray') {
            return null;
        }

        /** @var mixed $payload */
        $payload = $payload->jsonSerialize();

        return \is_array($payload) ? $payload : [];
    }

    /**
     * @return array<non-empty-string, string>
     */
    private static function dumpFields(array $payload): array
    {
        /** @var mixed $dump */
        $dump = $payload['payload'] ?? null;
        \is_array($dump) or $dump = [];
        /** @var mixed $source */
        $source = $payload['context']['source'] ?? null;

        return [
            'label' => self::plain($dump['label'] ?? null),
            'value_type' => self::plain($dump['type'] ?? null),
            'language' => self::plain($dump['language'] ?? null),
            'file' => \is_array($source) ? self::plain($source['file'] ?? null) : '',
        ];
    }

    /**
     * @return array<non-empty-string, string>
     */
    private static function httpFields(array $payload): array
    {
        /** @var mixed $request */
        $request = $payload['request'] ?? null;
        \is_array($request) or $request = [];

        return [
            'method' => self::plain($request['method'] ?? null),
            'path' => '/' . self::plain($request['uri'] ?? null),
            'host' => self::plain($payload['host'] ?? null),
        ];
    }

    /**
     * @return array<non-empty-string, list<string>>
     */
    private static function rayFields(array $payload): array
    {
        $fields = ['payload_type' => [], 'exception' => [], 'label' => [], 'color' => []];
        foreach (\is_array($payload['payloads'] ?? null) ? $payload['payloads'] : [] as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $type = self::plain($item['type'] ?? null);
            $fields['payload_type'][] = $type;
            /** @var mixed $content */
            $content = $item['content'] ?? null;
            if (!\is_array($content)) {
                continue;
            }

            match ($type) {
                'exception' => $fields['exception'][] = self::plain($content['class'] ?? null),
                'label' => $fields['label'][] = self::plain($content['label'] ?? null),
                'color' => $fields['color'][] = self::plain($content['color'] ?? null),
                default => null,
            };
        }

        return \array_map(static fn(array $values): array => \array_values(\array_unique(\array_filter($values))), $fields);
    }

    /**
     * @param mixed $contacts Objects or arrays with `name` and `email`.
     *
     * @return list<string> The email of each contact, or its name when it has none.
     */
    private static function contactList(mixed $contacts): array
    {
        $result = [];
        /** @var mixed $contact */
        foreach (\is_iterable($contacts) ? $contacts : [] as $contact) {
            $fields = \is_object($contact) ? \get_object_vars($contact) : (array) $contact;
            $email = self::plain($fields['email'] ?? null);
            $result[] = $email === '' ? self::plain($fields['name'] ?? null) : $email;
        }

        return \array_values(\array_filter($result));
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

    private static function contacts(mixed $contacts): string
    {
        return \implode(', ', self::contactList($contacts));
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
