<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Frontend\Stub;

use Buggregator\Trap\Server\Event\ProvidesEvents;

/**
 * Maps {@see ChildFrame} after {@see UiModule} mapped its parent class.
 */
final class ChildUiModule implements ProvidesEvents
{
    public function eventMappers(): array
    {
        return [ChildFrame::class => ChildMapper::class];
    }

    public function eventTypes(): array
    {
        return [];
    }
}
