<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler;

use Buggregator\Trap\Handler\Http\ProvidesHttpMiddlewares;
use Buggregator\Trap\Module\Frontend\ProvidesUi;
use Buggregator\Trap\Module\Profiler\Config\SPX;
use Buggregator\Trap\Module\Profiler\Config\XDebug;
use Buggregator\Trap\Module\Profiler\Config\XHProf;
use Buggregator\Trap\Module\Profiler\Console\Renderer;
use Buggregator\Trap\Module\Profiler\Frontend\ApiController;
use Buggregator\Trap\Module\Profiler\Frontend\Mapper;
use Buggregator\Trap\Module\Profiler\Http\XDebugTrap;
use Buggregator\Trap\Module\Profiler\Http\XHProfTrap;
use Buggregator\Trap\Proto\ProvidesFrameTypes;
use Buggregator\Trap\Sender\Console\ProvidesRenderers;
use Buggregator\Trap\Service\FilesObserver\ProvidesFileObservers;

/**
 * @internal
 */
final class Module implements
    ProvidesHttpMiddlewares,
    ProvidesFileObservers,
    ProvidesFrameTypes,
    ProvidesRenderers,
    ProvidesUi
{
    #[\Override]
    public function httpMiddlewares(): array
    {
        return [XHProfTrap::class, XDebugTrap::class];
    }

    #[\Override]
    public function fileObserverConfigs(): array
    {
        return [XHProf::class, XDebug::class, SPX::class];
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
        return [ApiController::class];
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
        return ['profiler'];
    }
}
