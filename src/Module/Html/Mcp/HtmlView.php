<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Html\Mcp;

use Buggregator\Trap\Module\Html\Frontend\Mapper;
use Buggregator\Trap\Module\Html\Frontend\Payload;
use Buggregator\Trap\Module\Mcp\View\EventFields;
use Buggregator\Trap\Module\Mcp\View\EventView;
use Buggregator\Trap\Module\Mcp\View\PayloadSummary;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\Record;
use Buggregator\Trap\Support\Measure;

/**
 * Pages for the MCP tools: the summary is the title and size, the text is the title, size and the markup.
 * The `html` events of binary data have a view of their own, {@see BinaryView}; the `format` field is theirs.
 *
 * @internal
 */
final class HtmlView implements EventView, EventFields
{
    #[\Override]
    public function summary(Event $event): ?string
    {
        $page = self::page($event);
        if ($page === null) {
            return null;
        }

        $name = $page->title !== '' ? $page->title : ($page->text !== '' ? $page->text : 'HTML page');
        $size = Measure::memory($page->size);
        $summary = PayloadSummary::shorten($name);

        return "$summary ($size)";
    }

    #[\Override]
    public function text(Record $record): ?string
    {
        $page = self::page($record->event);
        if ($page === null) {
            return null;
        }

        $lines = $page->title === '' ? [] : ["Title: {$page->title}"];
        $lines[] = \sprintf('Size: %d bytes', $page->size);

        return \implode("\n", $lines) . "\n\n" . $page->html;
    }

    #[\Override]
    public function fieldNames(): array
    {
        return [Mapper::TYPE => ['title', 'kind', 'format']];
    }

    #[\Override]
    public function fields(Event $event): ?array
    {
        $page = self::page($event);

        return $page === null ? null : ['title' => $page->title, 'kind' => 'page'];
    }

    /**
     * @return Payload|null Null for an event of another type and for binary data.
     */
    private static function page(Event $event): ?Payload
    {
        return $event->payload instanceof Payload ? $event->payload : null;
    }
}
