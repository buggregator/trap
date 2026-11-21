<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebugTrace;

use Buggregator\Trap\Module\Console\ProvidesRenderers;
use Buggregator\Trap\Module\Frontend\ProvidesUi;
use Buggregator\Trap\Module\Mcp\ProvidesEventViews;
use Buggregator\Trap\Module\Mcp\ProvidesMcpTools;
use Buggregator\Trap\Module\XDebugTrace\Config\TraceDirectory;
use Buggregator\Trap\Module\XDebugTrace\Console\Renderer;
use Buggregator\Trap\Module\XDebugTrace\Frontend\ApiController;
use Buggregator\Trap\Module\XDebugTrace\Frontend\Mapper;
use Buggregator\Trap\Module\XDebugTrace\Http\TraceTrap;
use Buggregator\Trap\Module\XDebugTrace\Mcp\TraceCall;
use Buggregator\Trap\Module\XDebugTrace\Mcp\TraceCalls;
use Buggregator\Trap\Module\XDebugTrace\Mcp\TraceSummary;
use Buggregator\Trap\Module\XDebugTrace\Mcp\TraceView;
use Buggregator\Trap\Server\Event\ProvidesEvents;
use Buggregator\Trap\Server\Handler\Http\ProvidesHttpMiddlewares;
use Buggregator\Trap\Server\Proto\ProvidesFrameTypes;
use Buggregator\Trap\Server\Service\FilesObserver\ProvidesFileObservers;

/**
 * Xdebug function traces (`xdebug.mode=trace`, `xdebug.trace_format=1`): received over HTTP or from a directory,
 * kept as files by the {@see TraceStorage}, read call by call by the UI and the MCP tools.
 *
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
    ProvidesMcpTools
{
    #[\Override]
    public function httpMiddlewares(): array
    {
        return [TraceTrap::class];
    }

    #[\Override]
    public function fileObserverConfigs(): array
    {
        return [TraceDirectory::class];
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
        return [Frame::TYPE];
    }

    #[\Override]
    public function eventViews(): array
    {
        return [TraceView::class];
    }

    #[\Override]
    public function mcpTools(): array
    {
        return [TraceSummary::class, TraceCalls::class, TraceCall::class];
    }
}
