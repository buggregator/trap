<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Html;

use Buggregator\Trap\Module\Console\ProvidesRenderers;
use Buggregator\Trap\Module\Frontend\ProvidesUi;
use Buggregator\Trap\Module\Html\Binary\AssetsController;
use Buggregator\Trap\Module\Html\Binary\Mapper as BinaryMapper;
use Buggregator\Trap\Module\Html\Console\Renderer;
use Buggregator\Trap\Module\Html\Frontend\Mapper;
use Buggregator\Trap\Module\Html\Http\HtmlTrap;
use Buggregator\Trap\Module\Html\Mcp\BinaryView;
use Buggregator\Trap\Module\Html\Mcp\HtmlView;
use Buggregator\Trap\Module\Mcp\ProvidesEventViews;
use Buggregator\Trap\Server\Event\ProvidesEvents;
use Buggregator\Trap\Server\Handler\Http\ProvidesHttpMiddlewares;
use Buggregator\Trap\Server\Proto\Frame\Binary;
use Buggregator\Trap\Server\Proto\ProvidesFrameTypes;

/**
 * The sender's own HTML pages: a report, a link to a tool's page, a kind of event Trap has no module for.
 * Also the `html` events of the traffic no protocol took, a hexdump and a viewer of the bytes; the console
 * prints that traffic by itself.
 *
 * @internal
 */
final class Module implements
    ProvidesHttpMiddlewares,
    ProvidesFrameTypes,
    ProvidesRenderers,
    ProvidesEvents,
    ProvidesEventViews,
    ProvidesUi
{
    #[\Override]
    public function httpMiddlewares(): array
    {
        return [HtmlTrap::class];
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
    public function eventMappers(): array
    {
        return [Frame::class => Mapper::class, Binary::class => BinaryMapper::class];
    }

    #[\Override]
    public function eventTypes(): array
    {
        return [Mapper::TYPE];
    }

    #[\Override]
    public function eventViews(): array
    {
        return [BinaryView::class, HtmlView::class];
    }

    #[\Override]
    public function uiApiControllers(): array
    {
        return [];
    }

    #[\Override]
    public function uiAssetsControllers(): array
    {
        return [AssetsController::class];
    }
}
