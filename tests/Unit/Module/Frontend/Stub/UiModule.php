<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Frontend\Stub;

use Buggregator\Trap\Module\Frontend\ProvidesUi;
use Buggregator\Trap\Server\Event\ProvidesEvents;
use Buggregator\Trap\Server\Proto\Frame\Binary;

final class UiModule implements ProvidesUi, ProvidesEvents
{
    public function uiApiControllers(): array
    {
        return [ApiController::class];
    }

    public function uiAssetsControllers(): array
    {
        return [AssetsController::class];
    }

    public function eventMappers(): array
    {
        return [Binary::class => BinaryMapper::class, ParentFrame::class => BinaryMapper::class];
    }

    public function eventTypes(): array
    {
        return ['binary'];
    }
}
