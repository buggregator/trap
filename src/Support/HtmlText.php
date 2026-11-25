<?php

declare(strict_types=1);

namespace Buggregator\Trap\Support;

/**
 * The text a reader of an HTML page sees.
 *
 * Scans with plain string search, not lazy `.*?` patterns: a page of a megabyte would hit the backtrack limit
 * of PCRE, which makes `preg_replace()` return null.
 *
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class HtmlText
{
    /** Elements whose content a reader does not see as text. */
    private const HIDDEN = ['script', 'style', 'head', 'title', 'template', 'iframe', 'noembed', 'noframes'];

    /**
     * The visible text of the markup: without comments, hidden elements and tags, entities decoded,
     * whitespace collapsed to single spaces.
     *
     * @param int<0, max>|null $limit The most characters; null for the whole text.
     */
    public static function visible(string $markup, ?int $limit = null): string
    {
        $markup = self::withoutHidden($markup);
        // `<` not followed by a tag name is text, as `a < b`; a tag unclosed at the end takes the rest
        // A `>` in a value quoted right after `=` stays in the tag; a quote anywhere else is a character
        $text = \preg_replace('~<(?=[a-zA-Z/!?])(?:[^>=]++|=\s*+(?:"[^"]*+"|\'[^\']*+\')?+)*+(?:>|$)~', ' ', $markup)
            // Past the backtrack limit of PCRE: tags end at the first `>`
            ?? (string) \preg_replace('~<(?=[a-zA-Z/!?])[^>]*+(?:>|$)~', ' ', $markup);

        return self::cut(self::collapse(\html_entity_decode($text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8')), $limit);
    }

    /**
     * Single spaces in place of any run of whitespace, trimmed.
     */
    public static function collapse(string $text): string
    {
        $collapsed = \preg_replace('/\s+/u', ' ', $text)
            // Not UTF-8: the ASCII whitespace still collapses
            ?? (string) \preg_replace('/\s+/', ' ', $text);

        return \trim($collapsed);
    }

    /**
     * @param int<0, max>|null $limit The most characters; null for the whole text.
     */
    public static function cut(string $text, ?int $limit): string
    {
        return $limit === null || \mb_strlen($text, 'UTF-8') <= $limit ? $text : \mb_substr($text, 0, $limit, 'UTF-8');
    }

    /**
     * One pass over comments and hidden elements, whichever comes first: a `<!--` in a script, a style or
     * a textarea is their text, not a comment.
     */
    private static function withoutHidden(string $markup): string
    {
        $open = '~<!--|<(' . \implode('|', self::HIDDEN) . '|textarea)(?=[\s/>]|$)[^>]*+>?~i';
        $result = '';
        $offset = 0;
        // A head with no end after it: no later head has one either
        $headUnclosed = false;
        while (\preg_match($open, $markup, $match, \PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $match[0][1];
            $result .= \substr($markup, $offset, $start - $offset) . ' ';
            if ($match[0][0] === '<!--') {
                // `<!-->` and `<!--->` are whole comments
                if (\substr($markup, $start + 4, 1) === '>') {
                    $offset = $start + 5;
                } elseif (\substr($markup, $start + 4, 2) === '->') {
                    $offset = $start + 6;
                } elseif (($end = \strpos($markup, '-->', $start + 4)) !== false) {
                    $offset = $end + 3;
                } else {
                    return $result;
                }
                continue;
            }

            $name = \strtolower($match[1][0]);
            if ($name === 'textarea') {
                // Its text is seen as it is: no tags or comments in it
                $from = $start + \strlen($match[0][0]);
                $closed = \preg_match('~</textarea(?=[\s/>]|$)[^>]*+>?~i', $markup, $close, \PREG_OFFSET_CAPTURE, $from) === 1;
                $to = $closed ? $close[0][1] : \strlen($markup);
                $result .= ' ' . \str_replace('<', '&lt;', \substr($markup, $from, $to - $from)) . ' ';
                $offset = $closed ? $to + \strlen($close[0][0]) : $to;
                continue;
            }
            // The end tag of `head` is optional: the body ends the head as well
            $end = $name === 'head' ? '~</head(?=[\s/>]|$)[^>]*+>?|(?=<body(?:[\s/>]|$))~i' : "~</$name(?=[\\s/>]|$)[^>]*+>?~i";
            if (!($name === 'head' && $headUnclosed)
                && \preg_match($end, $markup, $close, \PREG_OFFSET_CAPTURE, $start) === 1
            ) {
                $offset = $close[0][1] + \strlen($close[0][0]);
                continue;
            }

            // A browser reads the rest of the page as the text of an unclosed script or style
            if ($name !== 'head') {
                return $result;
            }
            $headUnclosed = true;
            $offset = $start + \strlen($match[0][0]);
        }

        return $result . \substr($markup, $offset);
    }
}
