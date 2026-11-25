<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Html\Frontend;

use Buggregator\Trap\Server\Event\Previewable;
use Buggregator\Trap\Server\Event\Searchable;
use Buggregator\Trap\Support\HtmlText;

/**
 * The `html` payload of a page: `{"title", "html", "size", "text"}`, the size in bytes of the markup, the text
 * the visible start of it.
 *
 * The feed gets `{"title", "size", "text"}` with the markup up to {@see PREVIEW_HTML} bytes, else
 * `"truncated": true` and the card shows the text, as buggregator/server sends it.
 *
 * The search finds the page by the title and the first {@see SEARCH_TEXT} characters of the visible text, made
 * once here: the UI does not get it, and a whole page of text would double the memory the page takes.
 *
 * @internal
 * @psalm-internal Buggregator\Trap\Module\Html
 */
final class Payload implements Previewable, Searchable
{
    /** Characters of the visible text in the payload. */
    public const TEXT_LENGTH = 300;

    /** Bytes of the markup the feed gets; a larger page is left out of it. */
    public const PREVIEW_HTML = 32 * 1024;

    /** Characters of the visible text the search finds the page by. */
    public const SEARCH_TEXT = 64 * 1024;

    /** @var int<0, max> */
    public readonly int $size;

    public readonly string $text;
    private readonly string $searchText;

    public function __construct(
        public readonly string $title,
        public readonly string $html,
    ) {
        $this->size = \strlen($html);
        $visible = HtmlText::visible($html);
        $this->text = HtmlText::cut($visible, self::TEXT_LENGTH);
        $this->searchText = \mb_strtolower($title . "\n" . HtmlText::cut($visible, self::SEARCH_TEXT), 'UTF-8');
    }

    /**
     * @return array{title: string, size: int<0, max>, text: string, html?: string, truncated?: true}
     */
    #[\Override]
    public function preview(): array
    {
        return ['title' => $this->title, 'size' => $this->size, 'text' => $this->text]
            + ($this->size <= self::PREVIEW_HTML ? ['html' => $this->html] : ['truncated' => true]);
    }

    /**
     * @return array{title: string, html: string, size: int<0, max>, text: string}
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return ['title' => $this->title, 'html' => $this->html, 'size' => $this->size, 'text' => $this->text];
    }

    #[\Override]
    public function searchText(): string
    {
        return $this->searchText;
    }
}
