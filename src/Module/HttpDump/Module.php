<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\HttpDump;

use Buggregator\Trap\Module\Console\ProvidesRenderers;
use Buggregator\Trap\Module\HttpDump\Console\Renderer;
use Buggregator\Trap\Module\HttpDump\Frontend\Mapper;
use Buggregator\Trap\Module\HttpDump\Http\DebugPage;
use Buggregator\Trap\Module\HttpDump\Http\Dump;
use Buggregator\Trap\Server\Event\ProvidesEvents;
use Buggregator\Trap\Server\Handler\Http\ProvidesHttpMiddlewares;
use Buggregator\Trap\Server\Proto\ProvidesFrameTypes;

/**
 * Dumps the HTTP requests no other module answered.
 *
 * Goes last in the module list: its middleware ends the pipeline.
 *
 * @internal
 */
final class Module implements ProvidesFrameTypes, ProvidesHttpMiddlewares, ProvidesRenderers, ProvidesEvents
{
    #[\Override]
    public function frameTypes(): array
    {
        return [Frame::TYPE => Frame::class];
    }

    #[\Override]
    public function httpMiddlewares(): array
    {
        return [DebugPage::class, Dump::class];
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
}
