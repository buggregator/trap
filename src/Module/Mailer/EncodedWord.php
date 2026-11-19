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
     * A word that fails to decode stays as is.
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

        // The parts go as: text, then charset, encoding and text of a word, then text again.
        $result = '';
        /** @var non-empty-string|null $charset Charset of the bytes collected from adjacent words. */
        $charset = null;
        $bytes = '';
        $count = \count($parts);
        for ($i = 0; $i < $count; $i += 4) {
            $text = $parts[$i];
            if ($i + 3 >= $count) {
                $result .= self::flush($charset, $bytes) . $text;
                break;
            }

            // Whitespace between two encoded words is folding, not a part of the text.
            if ($charset !== null && \trim($text) === '') {
                $text = '';
            }
            $text === '' or $result .= self::flush($charset, $bytes) . $text;

            $wordCharset = \strtoupper(\explode('*', $parts[$i + 1], 2)[0]);
            $decoded = self::decodeText($parts[$i + 2], $parts[$i + 3]);
            if ($decoded === null || $wordCharset === '') {
                $result .= self::flush($charset, $bytes) . "=?{$parts[$i + 1]}?{$parts[$i + 2]}?{$parts[$i + 3]}?=";
                continue;
            }

            // A multibyte character may be split between adjacent words of one charset.
            $charset === $wordCharset or $result .= self::flush($charset, $bytes);
            $charset = $wordCharset;
            $bytes .= $decoded;
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
     * Converts the collected bytes to UTF-8 and resets the collection.
     */
    private static function flush(?string &$charset, string &$bytes): string
    {
        $result = $charset === null || $bytes === '' ? $bytes : self::toUtf8($bytes, $charset);
        $charset = null;
        $bytes = '';

        return $result;
    }

    /**
     * Keeps the bytes as they are when no extension knows the charset.
     */
    private static function toUtf8(string $bytes, string $charset): string
    {
        if (\in_array($charset, ['UTF-8', 'UTF8', 'US-ASCII', 'ASCII'], true)) {
            return $bytes;
        }

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

        return $charset === 'ISO-8859-1' || $charset === 'LATIN1'
            ? self::latin1ToUtf8($bytes)
            : $bytes;
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
