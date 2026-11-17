<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Sentry\Mcp;

use Buggregator\Trap\Module\Sentry\Frame\EnvelopeItem;
use Buggregator\Trap\Module\Sentry\Frame\SentryEnvelope;
use Buggregator\Trap\Module\Sentry\Frame\SentryStore;
use Buggregator\Trap\Server\Event\Record;
use Buggregator\Trap\Support\Json;

/**
 * A Sentry event as compact plain text to debug from: the metadata, the exception chain from the thrown exception
 * to its root cause with the stack frames innermost first, the request, the contexts, the breadcrumbs and the extra
 * data. Every list is bounded by the constants; what is left out is counted in the text.
 *
 * Frames of libraries are those with `in_app: false` and those under a `vendor`, `node_modules` or `site-packages`
 * directory: some SDKs mark every frame as in-app. Runs of them are collapsed into one line, and so are the outermost
 * frames a cause shares with the exception it caused.
 *
 * The values of request headers and query parameters named as secrets, such as `Authorization`, and the passwords
 * of URLs are `[Filtered]`.
 *
 * @internal
 */
final class SentryText
{
    /** Exceptions of the chain, the thrown one included. */
    public const MAX_EXCEPTIONS = 10;

    /** Frame lines of a stack trace; a run of collapsed library frames takes one. */
    public const MAX_FRAMES = 30;

    /** Frames of a stack trace shown with their code: the innermost one and the application ones. */
    public const MAX_CODE_FRAMES = 3;

    /** Lines of code before and after the line of a frame. */
    public const CONTEXT_LINES = 3;

    /** Local variables of a frame shown with its code. */
    public const MAX_VARS = 10;

    /** Request headers; cookies and the headers of the browser, such as `sec-*` and `accept-*`, are left out. */
    public const MAX_HEADERS = 15;

    /** The latest breadcrumbs. */
    public const MAX_BREADCRUMBS = 10;

    /** Tags, user fields, contexts, extra entries. */
    public const MAX_ENTRIES = 20;

    /** Spans of a transaction. */
    public const MAX_SPANS = 20;

    /** Characters of a value in a line: a variable, a header, a tag, a context. */
    public const MAX_VALUE = 300;

    /** Characters of an exception value, a message, a request body. */
    public const MAX_TEXT = 2000;

    /** Characters of a line of code. */
    public const MAX_CODE_LINE = 200;

    /** Items of an envelope. */
    public const MAX_ITEMS = 20;

    /**
     * Bytes of the exception chain, so that the request, the contexts and the breadcrumbs stay within the 64 KB
     * a search looks through: a cause past them is left out; the thrown exception past them is shown without code
     * and variables, and then with fewer frame lines.
     */
    public const MAX_CHAIN_BYTES = 32 * 1024;

    private const SKIPPED_HEADERS = '/^(cookie|connection|accept-encoding|accept-language|cache-control|pragma|priority|dnt'
        . '|upgrade-insecure-requests|sec-.*)$/i';

    /** Names of request headers and query parameters whose values are replaced with {@see self::FILTERED}. */
    private const SECRET_NAMES = '/authori[sz]ation|authentication|token|secret|passw(or)?d|api[-_]?key|cookie/i';

    private const FILTERED = '[Filtered]';

    /** Item types whose payload is an event; the payloads of others, as `session` or `client_report`, take a line. */
    private const EVENT_ITEMS = ['event', 'transaction', 'feedback'];

    /**
     * Of a Sentry record: of every item of an envelope, else of the payload.
     */
    public static function render(Record $record): string
    {
        $items = [];
        foreach ($record->frames as $frame) {
            if ($frame instanceof SentryEnvelope) {
                $items = [...$items, ...$frame->items];
            } elseif ($frame instanceof SentryStore) {
                $items[] = new EnvelopeItem(['type' => 'event'], $frame->message);
            }
        }

        if (\count($items) <= 1) {
            /** @var mixed $payload */
            $payload = $items[0]->payload ?? $record->event->payload;
            /** @var mixed $type */
            $type = $items[0]->headers['type'] ?? null;
            if (\is_array($payload) && ($type === null || \in_array($type, self::EVENT_ITEMS, true))) {
                return self::event($payload, $type);
            }

            $items === [] and $items = [new EnvelopeItem([], $payload)];
        }

        $parts = [];
        $count = \count($items);
        foreach (\array_slice($items, 0, self::MAX_ITEMS) as $i => $item) {
            /** @var mixed $type */
            $type = $item->headers['type'] ?? null;
            $parts[] = \sprintf("--- Item %d of %d: %s ---\n", $i + 1, $count, self::inline($type ?? 'item')) . match (true) {
                !\is_array($item->payload) => self::attachment($item->headers),
                $type === null || \in_array($type, self::EVENT_ITEMS, true) => self::event($item->payload, $type),
                default => self::itemLine($item->payload),
            };
        }

        $count > self::MAX_ITEMS and $parts[] = \sprintf("… %d more items …\n", $count - self::MAX_ITEMS);

        return \implode("\n", $parts);
    }

    /**
     * Of `exception.values`, or of `exception` as older SDKs send it, a list or a single exception:
     * from the root cause to the thrown one.
     *
     * @return list<array<array-key, mixed>>
     */
    public static function exceptions(array $event): array
    {
        /** @var mixed $exceptions */
        $exceptions = $event['exception'] ?? null;
        if (\is_array($exceptions)) {
            /** @var mixed $exceptions */
            $exceptions = match (true) {
                \array_key_exists('values', $exceptions) => $exceptions['values'],
                \array_key_exists('type', $exceptions), \array_key_exists('stacktrace', $exceptions) => [$exceptions],
                default => $exceptions,
            };
        }

        return self::arrays($exceptions);
    }

    /**
     * The first of `logentry.formatted`, `message` (a string or its `formatted`), `logentry.message`.
     */
    public static function message(array $event): mixed
    {
        /** @var mixed $logentry */
        $logentry = $event['logentry'] ?? null;
        /** @var mixed $message */
        $message = $event['message'] ?? null;
        $candidates = [
            \is_array($logentry) ? $logentry['formatted'] ?? null : null,
            \is_array($message) ? $message['formatted'] ?? null : $message,
            \is_array($logentry) ? $logentry['message'] ?? null : $logentry,
            \is_array($message) ? $message['message'] ?? null : null,
        ];

        /** @var mixed $candidate */
        foreach ($candidates as $candidate) {
            if ($candidate !== null && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private static function event(array $event, mixed $itemType = null): string
    {
        $sections = [
            self::metadata($event, $itemType),
            self::exceptionChain($event),
            self::threadTrace($event),
            self::request($event),
            self::entries('Contexts', $event['contexts'] ?? null),
            self::breadcrumbs($event),
            self::entries('Extra', $event['extra'] ?? null),
            self::spans($event),
        ];

        return \implode("\n", \array_filter($sections, static fn(string $section): bool => $section !== ''));
    }

    private static function metadata(array $event, mixed $itemType): string
    {
        /** @var mixed $type */
        $type = $event['type'] ?? $itemType;
        /** @var mixed $sdk */
        $sdk = $event['sdk'] ?? null;
        /** @var mixed $fingerprint */
        $fingerprint = $event['fingerprint'] ?? null;

        $lines = [
            'Event ID' => $event['event_id'] ?? null,
            'Type' => $type === 'event' ? null : $type,
            'Level' => $event['level'] ?? null,
            'Message' => self::text(self::message($event)),
            'Transaction' => $event['transaction'] ?? null,
            'Duration' => self::duration($event),
            'Environment' => $event['environment'] ?? null,
            'Release' => $event['release'] ?? null,
            'Server' => $event['server_name'] ?? null,
            'Platform' => $event['platform'] ?? null,
            'Logger' => $event['logger'] ?? null,
            'SDK' => \is_array($sdk) ? \trim(self::inline($sdk['name'] ?? '') . ' ' . self::inline($sdk['version'] ?? '')) : $sdk,
            'Fingerprint' => \is_array($fingerprint) ? self::list($fingerprint) : null,
            'Tags' => self::pairs($event['tags'] ?? null),
            'User' => self::pairs($event['user'] ?? null),
        ];

        $result = '';
        /** @var mixed $value */
        foreach ($lines as $name => $value) {
            $value = $name === 'Message' ? \str_replace("\n", "\n  ", (string) $value) : self::inline($value);
            $value === '' or $result .= "$name: $value\n";
        }

        return $result;
    }

    private static function exceptionChain(array $event): string
    {
        $exceptions = \array_reverse(self::exceptions($event));
        if ($exceptions === []) {
            return '';
        }

        $count = \count($exceptions);
        $result = $count === 1
            ? "Exception, stack frames innermost first:\n"
            : "Exception chain of $count, the thrown one first, then what caused it; stack frames innermost first:\n";
        $shown = 0;
        $above = [];
        $withCode = [];
        foreach (\array_slice($exceptions, 0, self::MAX_EXCEPTIONS) as $i => $exception) {
            /** @var mixed $own */
            $own = $exception['stacktrace']['frames'] ?? null;
            $frames = self::arrays(\is_array($own) && $own !== [] && \array_is_list($own)
                ? $own
                : self::threadFrames($event, $exception) ?? $own);
            $part = $i === 0
                ? self::thrown($exception, $frames, $withCode, self::MAX_CHAIN_BYTES - \strlen($result))
                : self::exception($exception, false, $frames, $above, $withCode);
            if ($i > 0 && \strlen($result) + \strlen($part) > self::MAX_CHAIN_BYTES) {
                break;
            }

            $result .= $part;
            $above = $frames;
            ++$shown;
        }

        $count > $shown and $result .= \sprintf("… %d more causes …\n", $count - $shown);

        return $result;
    }

    /**
     * The thrown exception within the given bytes: past them without code and variables, then with fewer frame lines.
     *
     * @param list<array<array-key, mixed>> $frames Outermost first, as Sentry sends them.
     * @param array<string, true> $withCode
     */
    private static function thrown(array $exception, array $frames, array &$withCode, int $bytes): string
    {
        $shown = $withCode;
        $result = self::exception($exception, true, $frames, [], $shown);
        if (\strlen($result) <= $bytes) {
            $withCode = $shown;
            return $result;
        }

        for ($lines = self::MAX_FRAMES; $lines > 0; --$lines) {
            $result = self::exception($exception, true, $frames, [], $withCode, false, $lines);
            if (\strlen($result) <= $bytes) {
                return $result;
            }
        }

        return self::exception($exception, true, $frames, [], $withCode, false, 0);
    }

    /**
     * @param list<array<array-key, mixed>> $frames Outermost first, as Sentry sends them.
     * @param list<array<array-key, mixed>> $above The frames of the exception this one caused.
     * @param array<string, true> $withCode
     * @param bool $code Whether frames are shown with their code.
     * @param int<0, max> $maxLines Frame lines of the stack trace.
     */
    private static function exception(
        array $exception,
        bool $thrown,
        array $frames,
        array $above,
        array &$withCode,
        bool $code = true,
        int $maxLines = self::MAX_FRAMES,
    ): string {
        $type = self::inline($exception['type'] ?? null);
        $value = self::text($exception['value'] ?? null);
        $title = $type === '' ? 'Exception' : $type;
        $value === '' or $title .= ': ' . \str_replace("\n", "\n  ", $value);
        $result = ($thrown ? '' : 'Caused by ') . $title . "\n";

        /** @var mixed $mechanism */
        $mechanism = $exception['mechanism'] ?? null;
        $mechanism = \is_array($mechanism) ? \implode(', ', \array_filter([
            self::inline($mechanism['type'] ?? ''),
            match ($mechanism['handled'] ?? null) {
                true => 'handled',
                false => 'unhandled',
                default => '',
            },
        ])) : '';
        $mechanism === '' or $result .= "  Mechanism: $mechanism\n";

        $common = self::commonFrames($frames, $above);
        $result .= self::trace(\array_slice($frames, $common), $withCode, $code, $maxLines);
        $common > 0 and $result .= \sprintf(
            "  … %d %s in common with the exception above …\n",
            $common,
            $common === 1 ? 'frame' : 'frames',
        );

        return $result;
    }

    /**
     * The frames of the thread an exception was thrown in, as native SDKs send them: of the thread of its
     * `thread_id`, else of the crashed one.
     */
    private static function threadFrames(array $event, array $exception): mixed
    {
        $threads = self::arrays($event['threads']['values'] ?? $event['threads'] ?? null);
        /** @var mixed $id */
        $id = $exception['thread_id'] ?? null;
        foreach ($threads as $thread) {
            /** @var mixed $threadId */
            $threadId = $thread['id'] ?? null;
            // SDKs send an ID as a number or as a string
            if (\is_scalar($id) && \is_scalar($threadId) && (string) $threadId === (string) $id) {
                return $thread['stacktrace']['frames'] ?? null;
            }
        }

        foreach ($threads as $thread) {
            if (($thread['crashed'] ?? false) === true) {
                return $thread['stacktrace']['frames'] ?? null;
            }
        }

        return null;
    }

    /**
     * The number of the outermost frames a cause shares with the exception above it; the innermost one is kept.
     *
     * @param list<array<array-key, mixed>> $frames Outermost first, as Sentry sends them.
     * @param list<array<array-key, mixed>> $above
     */
    private static function commonFrames(array $frames, array $above): int
    {
        $common = 0;
        $max = \min(\count($frames) - 1, \count($above));
        while ($common < $max && self::frameKey($frames[$common]) === self::frameKey($above[$common])) {
            ++$common;
        }

        return $common;
    }

    /**
     * What tells a frame from another: the file, the line and the function.
     */
    private static function frameKey(array $frame): string
    {
        return self::json([$frame['filename'] ?? $frame['abs_path'] ?? null, $frame['lineno'] ?? null, $frame['function'] ?? null]);
    }

    /**
     * The stack trace of an event without exceptions: of the event, or of its crashed or current thread.
     */
    private static function threadTrace(array $event): string
    {
        if (self::exceptions($event) !== []) {
            return '';
        }

        /** @var mixed $frames */
        $frames = $event['stacktrace']['frames'] ?? null;
        $title = 'Stack trace';
        if (!\is_array($frames)) {
            $threads = \array_values(\array_filter(
                self::arrays($event['threads']['values'] ?? $event['threads'] ?? null),
                static fn(array $thread): bool => \is_array($thread['stacktrace']['frames'] ?? null),
            ));
            $thread = \array_filter($threads, static fn(array $thread): bool => ($thread['crashed'] ?? false) === true)
                ?: \array_filter($threads, static fn(array $thread): bool => ($thread['current'] ?? false) === true)
                ?: $threads;
            $thread = \reset($thread);
            if ($thread !== false) {
                /** @var mixed $frames */
                $frames = $thread['stacktrace']['frames'] ?? null;
                $name = self::inline($thread['name'] ?? $thread['id'] ?? '');
                $name === '' or $title .= " of thread $name";
            }
        }

        $withCode = [];

        return \is_array($frames) ? "$title, frames innermost first:\n" . self::trace($frames, $withCode) : '';
    }

    /**
     * @param array<string, true> $withCode The keys of the frames already shown with their code, not shown again.
     * @param bool $code Whether frames are shown with their code.
     * @param int<0, max> $maxLines Frame lines; the frames past them are counted.
     */
    private static function trace(
        array $frames,
        array &$withCode,
        bool $code = true,
        int $maxLines = self::MAX_FRAMES,
    ): string {
        $frames = \array_reverse(self::arrays($frames));
        $count = \count($frames);
        $collapse = \array_filter($frames, self::isApp(...)) !== [];
        $result = '';
        $lines = 0;
        $snippets = 0;
        $i = 0;
        while ($i < $count) {
            if ($lines === $maxLines) {
                $result .= \sprintf("  … %d more frames …\n", $count - $i);
                break;
            }

            ++$lines;
            $frame = $frames[$i];
            $isApp = self::isApp($frame);
            $end = $i;
            while ($collapse && $i > 0 && !$isApp && $end + 1 < $count && !self::isApp($frames[$end + 1])) {
                ++$end;
            }

            if ($end > $i) {
                $result .= \sprintf("  … %d vendor frames #%d-#%d …\n", $end - $i + 1, $i, $end);
                $i = $end + 1;
                continue;
            }

            $result .= '  ' . self::frameLine($i, $frame) . "\n";
            $key = self::frameKey($frame);
            if ($code && ($isApp || $i === 0) && $snippets < self::MAX_CODE_FRAMES && !isset($withCode[$key])) {
                $snippet = self::code($frame);
                if ($snippet !== '') {
                    ++$snippets;
                    $withCode[$key] = true;
                }

                $result .= $snippet;
            }

            ++$i;
        }

        return $result;
    }

    private static function isApp(array $frame): bool
    {
        if (($frame['in_app'] ?? null) === false) {
            return false;
        }

        $path = \str_replace('\\', '/', self::inline($frame['abs_path'] ?? $frame['filename'] ?? ''));

        return \preg_match('~(^|/)(vendor|node_modules|site-packages)/~', $path) !== 1;
    }

    private static function frameLine(int $index, array $frame): string
    {
        $path = self::inline($frame['filename'] ?? $frame['abs_path'] ?? $frame['module'] ?? '[unknown]');
        /** @var mixed $lineno */
        $lineno = $frame['lineno'] ?? null;
        /** @var mixed $colno */
        $colno = $frame['colno'] ?? null;
        $line = \is_int($lineno) ? ":$lineno" : '';
        $line !== '' && \is_int($colno) and $line .= ":$colno";
        $function = self::inline($frame['function'] ?? '');
        $class = self::inline($frame['class'] ?? '');
        $module = self::inline($frame['module'] ?? '');
        $callee = match (true) {
            $function === '' => '',
            $class !== '' => "$class::$function",
            $module !== '' && !\str_contains($function, $module) => "$module.$function",
            default => $function,
        };

        return "#$index $path$line" . ($callee === '' ? '' : " in $callee");
    }

    /**
     * The lines around the line of the frame, its absolute path and variables; empty without the line.
     */
    private static function code(array $frame): string
    {
        /** @var mixed $line */
        $line = $frame['context_line'] ?? null;
        if (!\is_string($line)) {
            return '';
        }

        /** @var mixed $pre */
        $pre = $frame['pre_context'] ?? null;
        /** @var mixed $post */
        $post = $frame['post_context'] ?? null;
        $pre = \is_array($pre) ? \array_slice(\array_values($pre), -self::CONTEXT_LINES) : [];
        $post = \is_array($post) ? \array_slice(\array_values($post), 0, self::CONTEXT_LINES) : [];
        $rows = \array_map(
            static fn(mixed $row): string => \rtrim(\str_replace("\t", '    ', \is_string($row) ? $row : '')),
            [...$pre, $line, ...$post],
        );
        $indent = \min(\array_map(
            static fn(string $row): int => \strlen($row) - \strlen(\ltrim($row, ' ')),
            \array_filter($rows, static fn(string $row): bool => $row !== '') ?: [''],
        ));
        /** @var mixed $lineno */
        $lineno = $frame['lineno'] ?? null;
        $first = \is_int($lineno) ? $lineno - \count($pre) : null;
        $width = $first === null ? 0 : \strlen((string) ($first + \count($rows) - 1));

        $result = '';
        $abs = self::inline($frame['abs_path'] ?? '');
        $abs !== '' && $abs !== self::inline($frame['filename'] ?? '') and $result .= "     path: $abs\n";
        foreach ($rows as $i => $row) {
            $number = $first === null ? '' : \str_pad((string) ($first + $i), $width, ' ', \STR_PAD_LEFT) . ' ';
            $result .= \rtrim(\sprintf(
                '   %s %s| %s',
                $i === \count($pre) ? '>' : ' ',
                $number,
                self::cut(\substr($row, $indent), self::MAX_CODE_LINE),
            )) . "\n";
        }

        /** @var mixed $vars */
        $vars = $frame['vars'] ?? null;
        if (\is_array($vars) && $vars !== []) {
            $result .= "     vars:\n";
            /** @var mixed $value */
            foreach (\array_slice($vars, 0, self::MAX_VARS, true) as $name => $value) {
                $result .= '       ' . self::inline($name) . ' = ' . ($value === null ? 'null' : self::inline($value)) . "\n";
            }

            \count($vars) > self::MAX_VARS and $result .= \sprintf("       … %d more …\n", \count($vars) - self::MAX_VARS);
        }

        return $result;
    }

    private static function request(array $event): string
    {
        /** @var mixed $request */
        $request = $event['request'] ?? null;
        if (!\is_array($request) || $request === []) {
            return '';
        }

        $method = self::inline($request['method'] ?? '');
        $result = 'Request: ' . \trim($method . ' ' . self::filterUrl(self::inline($request['url'] ?? ''))) . "\n";

        /** @var mixed $query */
        $query = $request['query_string'] ?? null;
        $query = \is_array($query) ? self::pairs($query, filter: true) : self::filterQuery(self::inline($query));
        $query === '' or $result .= "  Query: $query\n";

        $headers = [];
        /** @var mixed $all */
        $all = $request['headers'] ?? null;
        /** @var mixed $value */
        foreach (\is_array($all) ? $all : [] as $name => $value) {
            $pair = self::pair($name, $value);
            /** @var mixed $value */
            $value = $pair[1];
            $name = $pair[0];
            \preg_match(self::SKIPPED_HEADERS, $name) === 1 or $headers[] = "    $name: " . (self::isSecret($name)
                ? self::FILTERED
                : self::inline(\is_array($value) ? \implode(', ', \array_map(self::inline(...), $value)) : $value));
        }

        if ($headers !== []) {
            $result .= "  Headers:\n" . \implode("\n", \array_slice($headers, 0, self::MAX_HEADERS)) . "\n";
            \count($headers) > self::MAX_HEADERS
                and $result .= \sprintf("    … %d more …\n", \count($headers) - self::MAX_HEADERS);
        }

        /** @var mixed $data */
        $data = $request['data'] ?? null;
        $data === null || $data === '' || $data === [] or $result .= '  Data: ' . \str_replace("\n", "\n    ", self::text(
            \is_string($data) ? $data : self::json($data),
        )) . "\n";

        return $result;
    }

    /**
     * A section of `name: value` lines, a context of its own fields.
     */
    private static function entries(string $title, mixed $entries): string
    {
        if (!\is_array($entries) || $entries === []) {
            return '';
        }

        $result = "$title:\n";
        /** @var mixed $value */
        foreach (\array_slice($entries, 0, self::MAX_ENTRIES, true) as $name => $value) {
            if (\is_array($value)) {
                // The type of a context repeats its name
                ($value['type'] ?? null) === $name and $value = \array_diff_key($value, ['type' => true]);
                $value = \array_is_list($value) ? self::list($value) : self::pairs($value);
            }

            $result .= '  ' . self::inline($name) . ': ' . ($value === null ? 'null' : self::inline($value)) . "\n";
        }

        \count($entries) > self::MAX_ENTRIES
            and $result .= \sprintf("  … %d more …\n", \count($entries) - self::MAX_ENTRIES);

        return $result;
    }

    private static function breadcrumbs(array $event): string
    {
        $lines = \array_values(\array_filter(
            \array_map(self::breadcrumb(...), self::arrays($event['breadcrumbs']['values'] ?? $event['breadcrumbs'] ?? null)),
            static fn(string $line): bool => $line !== '',
        ));
        $count = \count($lines);
        if ($count === 0) {
            return '';
        }

        $result = $count > self::MAX_BREADCRUMBS
            ? \sprintf("Breadcrumbs, the last %d of %d:\n", self::MAX_BREADCRUMBS, $count)
            : "Breadcrumbs:\n";
        foreach (\array_slice($lines, -self::MAX_BREADCRUMBS) as $line) {
            $result .= "  $line\n";
        }

        return $result;
    }

    /**
     * @return string Empty for a breadcrumb without fields.
     */
    private static function breadcrumb(array $crumb): string
    {
        $category = self::inline($crumb['category'] ?? '');
        $message = self::inline($crumb['message'] ?? '');
        $parts = [
            self::time($crumb['timestamp'] ?? null),
            self::inline($crumb['level'] ?? ''),
            self::inline($crumb['type'] ?? ''),
            $category === '' || $message === '' ? $category . $message : "$category: $message",
        ];
        /** @var mixed $data */
        $data = $crumb['data'] ?? null;
        \is_array($data) && \array_filter($data, static fn(mixed $value): bool => $value !== [] && $value !== null) !== []
            and $parts[] = self::inline($data);

        return \implode(' ', \array_filter($parts, static fn(string $part): bool => $part !== ''));
    }

    private static function spans(array $event): string
    {
        $spans = self::arrays($event['spans'] ?? null);
        $count = \count($spans);
        if ($count === 0) {
            return '';
        }

        $result = "Spans ($count):\n";
        foreach (\array_slice($spans, 0, self::MAX_SPANS) as $span) {
            $line = self::inline($span['op'] ?? 'span');
            $description = self::inline($span['description'] ?? '');
            $description === '' or $line .= ": $description";
            $duration = self::duration($span);
            $duration === '' or $line .= " ($duration)";
            /** @var mixed $status */
            $status = $span['status'] ?? null;
            \is_string($status) && $status !== 'ok' and $line .= ", $status";
            $result .= '  ' . self::cut($line, self::MAX_VALUE) . "\n";
        }

        $count > self::MAX_SPANS and $result .= \sprintf("  … %d more …\n", $count - self::MAX_SPANS);

        return $result;
    }

    private static function attachment(array $headers): string
    {
        $parts = [];
        foreach (['filename', 'content_type', 'attachment_type'] as $key) {
            \is_string($headers[$key] ?? null) and $parts[] = self::inline($headers[$key]);
        }

        /** @var mixed $length */
        $length = $headers['length'] ?? null;
        \is_int($length) and $parts[] = "$length bytes";

        return ($parts === [] ? 'Not an event' : \implode(', ', $parts)) . "\n";
    }

    /**
     * The fields of an item that is not an event, as a session or a client report, in one line.
     */
    private static function itemLine(array $payload): string
    {
        $line = self::inline(self::pairs($payload));

        return ($line === '' ? 'Empty' : $line) . "\n";
    }

    /**
     * Of a map of names and values, or of a list of name and value pairs as Sentry takes tags.
     *
     * @param bool $filter Whether to hide the values of {@see self::SECRET_NAMES}.
     */
    private static function pairs(mixed $pairs, bool $filter = false): string
    {
        if (!\is_array($pairs) || $pairs === []) {
            return '';
        }

        $result = [];
        /** @var mixed $value */
        foreach (\array_slice($pairs, 0, self::MAX_ENTRIES, true) as $name => $value) {
            $pair = self::pair($name, $value);
            $result[] = $pair[0] . '=' . match (true) {
                !$filter => self::inline($pair[1]),
                self::isSecret($pair[0]) => self::FILTERED,
                default => self::inline(self::mask($pair[1])),
            };
        }

        \count($pairs) > self::MAX_ENTRIES and $result[] = \sprintf('… %d more', \count($pairs) - self::MAX_ENTRIES);

        return \implode(', ', $result);
    }

    /**
     * The name and value of an entry of a map, or of a list of name and value pairs.
     *
     * @return array{string, mixed} The name as one line.
     */
    private static function pair(int|string $key, mixed $value): array
    {
        if (\is_int($key) && \is_array($value) && \count($value) === 2 && \array_is_list($value)) {
            return [self::inline($value[0]), $value[1]];
        }

        return [self::inline($key), $value];
    }

    /**
     * @return list<array<array-key, mixed>> The arrays among the values.
     */
    private static function arrays(mixed $values): array
    {
        $result = [];
        /** @var mixed $value */
        foreach (\is_array($values) ? $values : [] as $value) {
            \is_array($value) and $result[] = $value;
        }

        return $result;
    }

    private static function isSecret(string $name): bool
    {
        return \preg_match(self::SECRET_NAMES, $name) === 1;
    }

    /**
     * A structure with the values of {@see self::SECRET_NAMES} replaced at any depth.
     */
    private static function mask(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        $result = [];
        /** @var mixed $item */
        foreach ($value as $name => $item) {
            /** @psalm-suppress MixedAssignment */
            $result[$name] = \is_string($name) && self::isSecret($name) ? self::FILTERED : self::mask($item);
        }

        return $result;
    }

    /**
     * A URL with the password of its user and the values of {@see self::SECRET_NAMES} in its query and fragment
     * replaced: OAuth puts `access_token` in the fragment.
     */
    private static function filterUrl(string $url): string
    {
        $fragment = \explode('#', $url, 2);
        $parts = \explode('?', $fragment[0], 2);
        $parts[0] = (string) \preg_replace('~^([a-z][a-z\d+.-]*://[^/:@]*):[^/]*@~i', '$1:' . self::FILTERED . '@', $parts[0]);
        isset($parts[1]) and $parts[1] = self::filterQuery($parts[1]);
        $fragment[0] = \implode('?', $parts);
        isset($fragment[1]) and $fragment[1] = self::filterQuery($fragment[1]);

        return \implode('#', $fragment);
    }

    /**
     * A query string with the values of {@see self::SECRET_NAMES} replaced; `;` separates parameters as `&` does.
     */
    private static function filterQuery(string $query): string
    {
        return (string) \preg_replace_callback(
            '/([^&;=]*)=([^&;]*)/',
            static fn(array $m): string => self::isSecret(\urldecode($m[1])) ? $m[1] . '=' . self::FILTERED : $m[0],
            $query,
        );
    }

    /**
     * Between `start_timestamp` and `timestamp` of a transaction or a span; empty when one is not a time.
     */
    private static function duration(array $item): string
    {
        $start = self::seconds($item['start_timestamp'] ?? null);
        $end = self::seconds($item['timestamp'] ?? null);

        return $start === null || $end === null ? '' : \sprintf('%.1f ms', ($end - $start) * 1000.0);
    }

    /**
     * Of a Unix time, or of a date as RFC 3339 that SDKs such as Python and Go send, UTC without an offset.
     *
     * @return float|null Seconds since the Unix epoch, null when the value is not a finite time.
     */
    private static function seconds(mixed $timestamp): ?float
    {
        if (\is_numeric($timestamp)) {
            $seconds = (float) $timestamp;

            return \is_finite($seconds) ? $seconds : null;
        }

        // The parser takes words such as `now` and `1 hour` too, as times relative to the current one
        if (!\is_string($timestamp) || \preg_match('/^\d{4}-\d\d-\d\d([T ]\d\d:\d\d.*)?$/', $timestamp) !== 1) {
            return null;
        }

        try {
            return (float) (new \DateTimeImmutable($timestamp, new \DateTimeZone('UTC')))->format('U.u');
        } catch (\Throwable) {
            return null;
        }
    }

    private static function list(array $values): string
    {
        return \implode(', ', \array_map(self::inline(...), \array_values($values)));
    }

    private static function time(mixed $timestamp): string
    {
        $seconds = self::seconds($timestamp);
        $time = $seconds === null ? false : \DateTimeImmutable::createFromFormat('U.u', \sprintf('%.6F', $seconds));

        return $time === false ? '' : $time->setTimezone(new \DateTimeZone('UTC'))->format('H:i:s.v');
    }

    /**
     * One line, cut to {@see self::MAX_VALUE}: a structure as JSON, null as empty.
     */
    private static function inline(mixed $value, int $max = self::MAX_VALUE): string
    {
        $text = match (true) {
            \is_string($value) => $value,
            \is_bool($value) => $value ? 'true' : 'false',
            $value === null => '',
            \is_scalar($value) => (string) $value,
            default => self::json($value),
        };
        $text = \preg_replace('/\s+/u', ' ', $text) ?? \preg_replace('/\s+/', ' ', $text) ?? $text;

        return self::cut(\trim($text), $max);
    }

    /**
     * Lines kept, cut to {@see self::MAX_TEXT}; empty for a missing value.
     */
    private static function text(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $text = \is_string($value) ? \str_replace("\r\n", "\n", $value) : self::inline($value, self::MAX_TEXT);

        return self::cut(\trim($text), self::MAX_TEXT);
    }

    private static function json(mixed $value): string
    {
        try {
            return Json::encode($value);
        } catch (\JsonException) {
            return '';
        }
    }

    private static function cut(string $text, int $max): string
    {
        return \mb_strlen($text) > $max ? \mb_substr($text, 0, $max) . '…' : $text;
    }
}
