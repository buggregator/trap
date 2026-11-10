<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Frontend\Stub;

use Buggregator\Trap\Module\Frontend\ProvidesUi;
use Buggregator\Trap\Server\Proto\Frame\Binary;

final class UiModule implements ProvidesUi
{
    public function uiApiControllers(): array
    {
        return [ApiController::class];
    }

    public function uiAssetsControllers(): array
    {
        return [AssetsController::class];
    }

    public function uiEventMappers(): array
    {
        return [Binary::class => BinaryMapper::class, ParentFrame::class => BinaryMapper::class];
    }

    public function uiEventTypes(): array
    {
        return ['binary'];
    }
}
