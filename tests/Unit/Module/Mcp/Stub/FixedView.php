<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Mcp\Stub;

use Buggregator\Trap\Module\Mcp\View\EventFields;
use Buggregator\Trap\Module\Mcp\View\EventView;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\Record;

/**
 * Knows the `fixed` events only, and fails on the `broken` ones.
 */
final class FixedView implements EventView, EventFields
{
    #[\Override]
    public function summary(Event $event): ?string
    {
        $event->type === 'broken' and throw new \RuntimeException('Broken payload.');

        return $event->type === 'fixed' ? 'fixed summary' : null;
    }

    #[\Override]
    public function text(Record $record): ?string
    {
        return $record->event->type === 'fixed' ? 'fixed text' : null;
    }

    #[\Override]
    public function fieldNames(): array
    {
        return ['fixed' => ['answer', 'empty'], 'monolog' => ['ignored']];
    }

    #[\Override]
    public function fields(Event $event): ?array
    {
        $event->type === 'broken' and throw new \RuntimeException('Broken payload.');

        return $event->type === 'fixed' ? ['answer' => 42, 'empty' => '', 'none' => []] : null;
    }
}
