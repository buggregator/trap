<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Csp;

use Buggregator\Trap\Module\Console\ProvidesRenderers;
use Buggregator\Trap\Module\Csp\Console\Renderer;
use Buggregator\Trap\Module\Csp\Frontend\Mapper;
use Buggregator\Trap\Module\Csp\Http\CspTrap;
use Buggregator\Trap\Module\Csp\Mcp\CspView;
use Buggregator\Trap\Module\Mcp\ProvidesEventViews;
use Buggregator\Trap\Server\Event\ProvidesEvents;
use Buggregator\Trap\Server\Handler\Http\ProvidesHttpMiddlewares;
use Buggregator\Trap\Server\Proto\ProvidesFrameTypes;

/**
 * Browser security reports: CSP violations and other Reporting API reports.
 *
 * @internal
 */
final class Module implements
    ProvidesHttpMiddlewares,
    ProvidesFrameTypes,
    ProvidesRenderers,
    ProvidesEvents,
    ProvidesEventViews
{
    #[\Override]
    public function httpMiddlewares(): array
    {
        return [CspTrap::class];
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
        return [Frame::class => Mapper::class];
    }

    #[\Override]
    public function eventTypes(): array
    {
        return [Mapper::TYPE];
    }

    #[\Override]
    public function eventViews(): array
    {
        return [CspView::class];
    }
}
