<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp\View;

use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\Record;

/**
 * How the MCP tools show an event to the agent: a summary line for the listings and a full text view.
 * The views are asked in turn, and the first answer that is not null wins.
 *
 * @internal
 */
interface EventView
{
    /**
     * @return non-empty-string|null One line; null to leave the event to the next view.
     */
    public function summary(Event $event): ?string;

    /**
     * @return string|null Plain text without ANSI codes; null to leave the event to the next view.
     */
    public function text(Record $record): ?string;
}
