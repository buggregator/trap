<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Ray;

use Buggregator\Trap\Module\Console\ProvidesRenderers;
use Buggregator\Trap\Module\Frontend\ProvidesUi;
use Buggregator\Trap\Module\Ray\Console\Renderer;
use Buggregator\Trap\Module\Ray\Frontend\LocksController;
use Buggregator\Trap\Module\Ray\Frontend\Mapper;
use Buggregator\Trap\Module\Ray\Http\RayTrap;
use Buggregator\Trap\Server\Handler\Http\ProvidesHttpMiddlewares;
use Buggregator\Trap\Server\Proto\ProvidesFrameTypes;

/**
 * @internal
 */
final class Module implements ProvidesHttpMiddlewares, ProvidesFrameTypes, ProvidesRenderers, ProvidesUi
{
    #[\Override]
    public function httpMiddlewares(): array
    {
        return [RayTrap::class];
    }

    #[\Override]
    public function frameTypes(): array
    {
        return [Frame::TYPE => Frame::class];
    }

    #[\Override]
    public function renderers(): array
    {
        return [Renderer::class];
    }

    #[\Override]
    public function uiApiControllers(): array
    {
        return [LocksController::class];
    }

    #[\Override]
    public function uiAssetsControllers(): array
    {
        return [];
    }

    #[\Override]
    public function uiEventMappers(): array
    {
        return [Frame::class => Mapper::class];
    }

    #[\Override]
    public function uiEventTypes(): array
    {
        return ['ray'];
    }
}
