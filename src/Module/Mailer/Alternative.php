<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mailer;

/**
 * Another version of an email body than its plain text and HTML: the Apple Watch version, an AMP version,
 * a calendar invite, a delivery report. A version is a part of any `text/*` type or of a type listed
 * in {@see self::LABELS} that has no file name and no `attachment` disposition.
 *
 * @psalm-type PartData = array{type: string, label: string, content: string, size: int<0, max>, truncated?: true}
 *
 * @internal
 */
final class Alternative
{
    /** Bytes of a version the web UI gets; the whole email stays in `raw`. */
    public const MAX_CONTENT = 1 << 20;

    /** Short names of the known versions, for a tab. */
    public const LABELS = [
        'text/watch-html' => 'Apple Watch',
        'text/x-amp-html' => 'AMP',
        'text/calendar' => 'Calendar',
        'application/ics' => 'Calendar',
        'text/enriched' => 'Enriched text',
        'text/richtext' => 'Rich text',
        'text/markdown' => 'Markdown',
        'text/x-markdown' => 'Markdown',
        'message/delivery-status' => 'Delivery status',
        'message/global-delivery-status' => 'Delivery status',
        'message/disposition-notification' => 'Read receipt',
        'text/rfc822-headers' => 'Original headers',
    ];

    /**
     * The media type of a Content-Type header, in lower case: `text/plain` when the header is empty.
     */
    public static function mediaType(string $contentType): string
    {
        $type = \strtolower(\trim(\explode(';', $contentType, 2)[0]));
        return $type === '' ? 'text/plain' : $type;
    }

    /**
     * Whether a part of this media type, unnamed and not attached, is a version: not the plain text or the HTML.
     */
    public static function isAlternativeType(string $mediaType): bool
    {
        return $mediaType !== 'text/plain'
            && $mediaType !== 'text/html'
            && (isset(self::LABELS[$mediaType]) || \str_starts_with($mediaType, 'text/'));
    }

    /**
     * The version as the web UI takes it, cut to {@see self::MAX_CONTENT} bytes on a character boundary.
     *
     * @return PartData
     */
    public static function toArray(string $mediaType, string $content): array
    {
        $part = [
            'type' => $mediaType,
            'label' => self::LABELS[$mediaType] ?? $mediaType,
            'content' => $content,
            'size' => \strlen($content),
        ];
        if ($part['size'] > self::MAX_CONTENT) {
            $cut = self::MAX_CONTENT;
            // Back to the first byte of a UTF-8 character
            while ($cut > 0 && (\ord($content[$cut]) & 0xC0) === 0x80) {
                --$cut;
            }
            $part['content'] = \substr($content, 0, $cut);
            $part['truncated'] = true;
        }

        return $part;
    }
}
