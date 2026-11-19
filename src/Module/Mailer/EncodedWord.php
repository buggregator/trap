<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mailer;

/**
 * Decodes the RFC 2047 encoded words of a header value, `=?charset?B|Q?text?=`, to UTF-8.
 *
 * @internal
 */
final class EncodedWord
{
    private const PATTERN = '/=\?([^?\s]+)\?([BbQq])\?([^?\s]*)\?=/';

    /**
     * A word that fails to decode, or whose charset no converter knows, stays as is.
     */
    public static function decode(string $value): string
    {
        if (!\str_contains($value, '=?')) {
            return $value;
        }

        $parts = \preg_split(self::PATTERN, $value, -1, \PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false || \count($parts) === 1) {
            return $value;
        }

        // A string is text, an array is a run of words: the source and its UTF-8 text, null if undecoded.
        /** @var list<string|array{string, string|null}> $items */
        $items = [];
        /** @var array{string, string, string}|null $run Charset, bytes and source of adjacent words of one charset. */
        $run = null;
        $count = \count($parts);
        // The parts go as: text, then charset, encoding and text of a word, then text again.
        for ($i = 0; $i < $count; $i += 4) {
            $text = $parts[$i];
            $last = $i + 3 >= $count;
            $source = $last ? '' : "=?{$parts[$i + 1]}?{$parts[$i + 2]}?{$parts[$i + 3]}?=";
            $charset = $last ? '' : \strtoupper(\explode('*', $parts[$i + 1], 2)[0]);
            $bytes = $last || $charset === '' ? null : self::decodeText($parts[$i + 2], $parts[$i + 3]);

            // A multibyte character may be split between adjacent words of one charset.
            if ($run !== null && $bytes !== null && $run[0] === $charset && \trim($text) === '') {
                $run = [$charset, $run[1] . $bytes, $run[2] . $text . $source];
                continue;
            }

            $run === null or $items[] = [$run[2], self::toUtf8($run[1], $run[0])];
            $run = null;
            $text === '' or $items[] = $text;
            if ($last) {
                break;
            }

            if ($bytes === null) {
                $items[] = [$source, null];
            } else {
                $run = [$charset, $bytes, $source];
            }
        }

        $result = '';
        foreach ($items as $index => $item) {
            if (\is_array($item)) {
                $result .= $item[1] ?? $item[0];
                continue;
            }

            // Whitespace between two decoded words is folding, not a part of the text.
            $prev = $items[$index - 1] ?? null;
            $next = $items[$index + 1] ?? null;
            $folding = \trim($item) === ''
                && \is_array($prev) && $prev[1] !== null
                && \is_array($next) && $next[1] !== null;
            $folding or $result .= $item;
        }

        return $result;
    }

    private static function decodeText(string $encoding, string $text): ?string
    {
        if (\strtoupper($encoding) === 'Q') {
            return \quoted_printable_decode(\str_replace('_', ' ', $text));
        }

        $decoded = \base64_decode($text, true);
        return $decoded === false ? null : $decoded;
    }

    /**
     * @return string|null Null when no converter knows the charset or the result is no valid UTF-8.
     */
    private static function toUtf8(string $bytes, string $charset): ?string
    {
        $converted = match (true) {
            \in_array($charset, ['UTF-8', 'UTF8', 'US-ASCII', 'ASCII'], true) => $bytes,
            $charset === 'ISO-8859-1' || $charset === 'LATIN1' => self::latin1ToUtf8($bytes),
            default => self::convert($bytes, $charset),
        };

        return $converted !== null && \preg_match('//u', $converted) === 1 ? $converted : null;
    }

    private static function convert(string $bytes, string $charset): ?string
    {
        if (\function_exists('mb_convert_encoding')) {
            try {
                $converted = @\mb_convert_encoding($bytes, 'UTF-8', $charset);
                if (\is_string($converted)) {
                    return $converted;
                }
            } catch (\ValueError) {
                // Unknown to mbstring: iconv may know it.
            }
        }

        if (\function_exists('iconv')) {
            $converted = @\iconv($charset, 'UTF-8', $bytes);
            if (\is_string($converted)) {
                return $converted;
            }
        }

        return null;
    }

    private static function latin1ToUtf8(string $bytes): string
    {
        return (string) \preg_replace_callback(
            '/[\x80-\xFF]/',
            static fn(array $m): string => \chr(0xC0 | (\ord($m[0]) >> 6)) . \chr(0x80 | (\ord($m[0]) & 0x3F)),
            $bytes,
        );
    }
}
