<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Frontend\Stub;

use Buggregator\Trap\Module\Frontend\ProvidesUi;

/**
 * Maps {@see ChildFrame} after {@see UiModule} mapped its parent class.
 */
final class ChildUiModule implements ProvidesUi
{
    public function uiApiControllers(): array
    {
        return [];
    }

    public function uiAssetsControllers(): array
    {
        return [];
    }

    public function uiEventMappers(): array
    {
        return [ChildFrame::class => ChildMapper::class];
    }

    public function uiEventTypes(): array
    {
        return [];
    }
}
