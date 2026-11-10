<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Monolog;

use Buggregator\Trap\Module\Frontend\ProvidesUi;
use Buggregator\Trap\Module\Monolog\Console\Renderer;
use Buggregator\Trap\Module\Monolog\Frontend\Mapper;
use Buggregator\Trap\Module\Monolog\Traffic\Dispatcher;
use Buggregator\Trap\Server\Proto\ProvidesFrameTypes;
use Buggregator\Trap\Server\Sender\Console\ProvidesRenderers;
use Buggregator\Trap\Server\Traffic\ProvidesDispatchers;

/**
 * @internal
 */
final class Module implements ProvidesDispatchers, ProvidesFrameTypes, ProvidesRenderers, ProvidesUi
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
    public function uiApiControllers(): array
    {
        return [];
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
        return ['monolog'];
    }
}
