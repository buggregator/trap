<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mailer\Traffic;

use Buggregator\Trap\Support\Charset;

/**
 * Parameters of a MIME header and the charset of a text part.
 *
 * @internal
 */
final class ContentType
{
    /** Charsets of a text already in UTF-8 */
    private const NO_CONVERSION = ['', 'utf-8', 'utf8', 'us-ascii', 'ascii'];

    /** Names mbstring takes for encodings that are not charsets: base64 decoding, HTML unescaping and alike */
    private const NOT_CHARSETS = [
        'base64' => true,
        'uuencode' => true,
        'html-entities' => true,
        'html' => true,
        'quoted-printable' => true,
        'qprint' => true,
        '7bit' => true,
        '8bit' => true,
        'binary' => true,
        'pass' => true,
        'wchar' => true,
        'auto' => true,
    ];

    /**
     * A text converted to UTF-8 from the charset of its Content-Type: the console and the web UI show text as is,
     * and the event JSON takes UTF-8 only. The bytes stay as they are in an unknown charset.
     */
    public static function toUtf8(string $value, string $contentType): string
    {
        $charset = self::parameter($contentType, 'charset');
        $charset = $charset === null ? '' : \strtolower(\trim($charset, "\"'"));
        // mbstring takes a comma-separated list for detecting the charset
        if (\in_array($charset, self::NO_CONVERSION, true)
            || isset(self::NOT_CHARSETS[$charset])
            || \str_contains($charset, ',')
        ) {
            return $value;
        }

        // Names mail clients write for charsets mbstring knows by another name
        $charset = match ($charset) {
            'ks_c_5601-1987' => 'UHC',
            'iso-8859-8-i' => 'ISO-8859-8',
            default => $charset,
        };

        return Charset::toUtf8($value, $charset) ?? $value;
    }

    /**
     * Value of the first header parameter of that name, as written.
     * Parameters match left to right, each taking its quoted value whole, so a `;` or `=` inside one starts none.
     */
    public static function parameter(string $header, string $parameter): ?string
    {
        \preg_match_all(
            '/;\\s*+([^\\s=;"]++)\\s*+=\\s*+("(?:[^"\\\\]|\\\\.)*+"|"[^"]*+"?|[^;"]*+)/',
            $header,
            $params,
            \PREG_SET_ORDER,
        );

        foreach ($params as $param) {
            if (\strcasecmp($param[1], $parameter) === 0) {
                return \rtrim($param[2]);
            }
        }

        return null;
    }
}
