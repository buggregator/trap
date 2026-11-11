<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Frontend\Stub;

use Buggregator\Trap\Module\Frontend\ProvidesUi;
use Buggregator\Trap\Module\HttpDump\Frame;

/**
 * Takes the frame class and the event type of the HTTP dump module.
 */
final class TakingHttpUi implements ProvidesUi
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
        return [Frame::class => BinaryMapper::class];
    }

    public function uiEventTypes(): array
    {
        return ['http-dump'];
    }
}
