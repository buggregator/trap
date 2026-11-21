<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebugTrace\Mcp;

use Buggregator\Trap\Module\Mcp\View\EventView;
use Buggregator\Trap\Module\XDebugTrace\Frame;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\Record;

/**
 * A summary line of a trace for the MCP listings; the console renderer gives the full text.
 *
 * @internal
 */
final class TraceView implements EventView
{
    #[\Override]
    public function summary(Event $event): ?string
    {
        if ($event->type !== Frame::TYPE || !\is_array($event->payload)) {
            return null;
        }

        /** @var array{name?: string, url?: string, summary?: array{main?: string, duration?: float, calls?: int, truncated?: bool}} $payload */
        $payload = $event->payload;
        $summary = $payload['summary'] ?? [];
        $title = ($payload['url'] ?? '') ?: ($payload['name'] ?? '') ?: ($summary['main'] ?? '') ?: 'Xdebug trace';

        return \sprintf(
            '%s, %.2f ms, %d calls%s',
            $title,
            ($summary['duration'] ?? 0.0) * 1e3,
            $summary['calls'] ?? 0,
            ($summary['truncated'] ?? false) ? ', cut' : '',
        );
    }

    #[\Override]
    public function text(Record $record): ?string
    {
        return null;
    }
}
