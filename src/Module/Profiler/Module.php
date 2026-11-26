<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler;

use Buggregator\Trap\Module\Console\ProvidesRenderers;
use Buggregator\Trap\Module\Frontend\ProvidesUi;
use Buggregator\Trap\Module\Mcp\ProvidesEventViews;
use Buggregator\Trap\Module\Mcp\ProvidesMcpTools;
use Buggregator\Trap\Module\Profiler\Config\SPX;
use Buggregator\Trap\Module\Profiler\Config\XDebug;
use Buggregator\Trap\Module\Profiler\Config\XHProf;
use Buggregator\Trap\Module\Profiler\Console\Renderer;
use Buggregator\Trap\Module\Profiler\Frontend\ApiController;
use Buggregator\Trap\Module\Profiler\Frontend\Mapper;
use Buggregator\Trap\Module\Profiler\Http\PprofTrap;
use Buggregator\Trap\Module\Profiler\Http\SpeedscopeTrap;
use Buggregator\Trap\Module\Profiler\Http\SPXTrap;
use Buggregator\Trap\Module\Profiler\Http\StacksTrap;
use Buggregator\Trap\Module\Profiler\Http\XDebugTrap;
use Buggregator\Trap\Module\Profiler\Http\XHProfTrap;
use Buggregator\Trap\Module\Profiler\Mcp\ProfileCallGraph;
use Buggregator\Trap\Module\Profiler\Mcp\ProfileCalls;
use Buggregator\Trap\Module\Profiler\Mcp\ProfileSummary;
use Buggregator\Trap\Module\Profiler\Mcp\ProfileTop;
use Buggregator\Trap\Module\Profiler\Mcp\ProfileView;
use Buggregator\Trap\Module\Profiler\Traffic\BlackfireDispatcher;
use Buggregator\Trap\Server\Event\ProvidesEvents;
use Buggregator\Trap\Server\Handler\Http\ProvidesHttpMiddlewares;
use Buggregator\Trap\Server\Proto\ProvidesFrameTypes;
use Buggregator\Trap\Server\Service\FilesObserver\ProvidesFileObservers;
use Buggregator\Trap\Server\Traffic\ProvidesDispatchers;

/**
 * @internal
 */
final class Module implements
    ProvidesHttpMiddlewares,
    ProvidesFileObservers,
    ProvidesFrameTypes,
    ProvidesRenderers,
    ProvidesUi,
    ProvidesEvents,
    ProvidesEventViews,
    ProvidesMcpTools,
    ProvidesDispatchers
{
    #[\Override]
    public function httpMiddlewares(): array
    {
        return [
            XHProfTrap::class,
            XDebugTrap::class,
            SpeedscopeTrap::class,
            PprofTrap::class,
            SPXTrap::class,
            StacksTrap::class,
        ];
    }

    /**
     * The Blackfire probe sends profiles over its own protocol, on any port of Trap.
     */
    #[\Override]
    public function dispatchers(): array
    {
        return [BlackfireDispatcher::class];
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
    public function eventMappers(): array
    {
        return [Frame::class => Mapper::class];
    }

    #[\Override]
    public function eventTypes(): array
    {
        return ['profiler'];
    }

    #[\Override]
    public function eventViews(): array
    {
        return [ProfileView::class];
    }

    #[\Override]
    public function mcpTools(): array
    {
        return [ProfileSummary::class, ProfileTop::class, ProfileCalls::class, ProfileCallGraph::class];
    }
}
