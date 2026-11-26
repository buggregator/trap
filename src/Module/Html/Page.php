<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Html;

use Buggregator\Trap\Support\Charset;
use Buggregator\Trap\Support\HtmlText;
use Buggregator\Trap\Support\Json;

/**
 * Reads a page from the body of a request: JSON `{"title": "…", "html": "…"}`, or the markup itself.
 *
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class Page
{
    /** Characters of a title. */
    public const MAX_TITLE = 300;

    /**
     * @param string $contentType The `Content-Type` header line; any type but `application/json` is markup.
     *
     * @return array{string, non-empty-string} The title, one line and maybe empty, and the UTF-8 markup.
     *
     * @throws \InvalidArgumentException When the JSON is malformed or the markup is empty.
     */
    public static function parse(string $contentType, string $body): array
    {
        $params = \explode(';', $contentType);
        $mediaType = \strtolower(\trim(\array_shift($params)));
        if ($mediaType === 'application/json') {
            [$title, $html] = self::json($body);
        } else {
            $title = '';
            $html = self::toUtf8($body, self::charset($params));
        }

        \trim($html) === '' and throw new \InvalidArgumentException('The markup is empty.');
        \assert($html !== '');

        $title = HtmlText::collapse($title);
        $title === '' and $title = self::pageTitle($html);

        return [HtmlText::cut($title, self::MAX_TITLE), $html];
    }

    /**
     * The text of the first `<title>` of the markup, entities decoded, whitespace collapsed.
     */
    public static function pageTitle(string $markup): string
    {
        if (\preg_match('~<title(?=[\s/>])[^>]*+>?~i', $markup, $open, \PREG_OFFSET_CAPTURE) !== 1) {
            return '';
        }

        $start = $open[0][1] + \strlen($open[0][0]);
        $end = \stripos($markup, '</title', $start);
        $text = $end === false ? '' : \substr($markup, $start, $end - $start);

        return HtmlText::collapse(\html_entity_decode($text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
    }

    /**
     * @return array{string, string}
     *
     * @throws \InvalidArgumentException
     */
    private static function json(string $body): array
    {
        try {
            /** @var mixed $data */
            $data = Json::decode($body);
        } catch (\JsonException) {
            $data = null;
        }

        $title = \is_array($data) ? $data['title'] ?? '' : null;
        $html = \is_array($data) ? $data['html'] ?? null : null;
        \is_string($title) && \is_string($html)
            or throw new \InvalidArgumentException('The JSON body must be {"title": "…", "html": "…"}.');

        return [$title, $html];
    }

    /**
     * @param list<string> $params The parameters of the content type.
     *
     * @return string Lower case; empty when the request declares none.
     */
    private static function charset(array $params): string
    {
        foreach ($params as $param) {
            [$name, $value] = \explode('=', $param, 2) + [1 => ''];
            if (\strtolower(\trim($name)) === 'charset') {
                return \strtolower(\trim($value, " \t\"'"));
            }
        }

        return '';
    }

    /**
     * Without a charset in the content type, a `<meta charset>` near the start declares it, as browsers read
     * it; undeclared markup that is not UTF-8 is read as windows-1252, the browsers' default. A byte order mark
     * wins over both, as in browsers: PowerShell 5.1 writes UTF-16 with one.
     */
    private static function toUtf8(string $markup, string $charset): string
    {
        $bom = match (true) {
            \str_starts_with($markup, "\xEF\xBB\xBF") => 'utf-8',
            \str_starts_with($markup, "\xFF\xFE") => 'utf-16le',
            \str_starts_with($markup, "\xFE\xFF") => 'utf-16be',
            default => null,
        };
        if ($bom !== null) {
            $markup = \substr($markup, $bom === 'utf-8' ? 3 : 2);
            // A stray last byte makes iconv refuse the whole of UTF-16
            $bom !== 'utf-8' && \strlen($markup) % 2 === 1 and $markup = \substr($markup, 0, -1);
            $charset = $bom;
        }

        $charset === '' && \preg_match(
            '~<meta(?=[\s/>])[^>]*?charset\s*=\s*["\']?\s*([\w.:-]+)~i',
            \substr($markup, 0, 1024),
            $meta,
        ) === 1 and $charset = \strtolower($meta[1]);

        if (\in_array($charset, ['', 'utf-8', 'utf8', 'us-ascii'], true)) {
            if (\mb_check_encoding($markup, 'UTF-8')) {
                return $markup;
            }
            $charset === '' and $charset = 'windows-1252';
        }

        $converted = Charset::toUtf8($markup, $charset);

        return \mb_scrub($converted ?? $markup, 'UTF-8');
    }
}
