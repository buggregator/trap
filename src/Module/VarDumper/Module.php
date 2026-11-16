<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\VarDumper;

use Buggregator\Trap\Module\Console\ProvidesRenderers;
use Buggregator\Trap\Module\VarDumper\Console\Renderer;
use Buggregator\Trap\Module\VarDumper\Frontend\Mapper;
use Buggregator\Trap\Module\VarDumper\Traffic\Dispatcher;
use Buggregator\Trap\Server\Event\ProvidesEvents;
use Buggregator\Trap\Server\Proto\ProvidesFrameTypes;
use Buggregator\Trap\Server\Traffic\ProvidesDispatchers;

/**
 * @internal
 */
final class Module implements ProvidesDispatchers, ProvidesFrameTypes, ProvidesRenderers, ProvidesEvents
{
    #[\Override]
    public function dispatchers(): array
    {
        return [Dispatcher::class];
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
        return ['var-dump'];
    }
}
