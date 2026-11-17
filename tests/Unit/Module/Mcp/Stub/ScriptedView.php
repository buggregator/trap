<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\Stub;

use Buggregator\Trap\Module\Mcp\View\EventView;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\Record;

/**
 * Renders every event as its `text` payload, after `$delay` microseconds, and counts the renders.
 */
final class ScriptedView implements EventView
{
    public int $delay = 0;
    public int $renders = 0;

    public static function event(string $uuid, string $text, float $time = 1.0): Event
    {
        return new Event($uuid, 'scripted', ['text' => $text], $time);
    }

    #[\Override]
    public function summary(Event $event): ?string
    {
        return 'scripted';
    }

    #[\Override]
    public function text(Record $record): ?string
    {
        ++$this->renders;
        $this->delay > 0 and \usleep($this->delay);
        /** @var array{text: string} $payload */
        $payload = $record->event->payload;

        return $payload['text'];
    }
}
