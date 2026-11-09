<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Tracing;

use Buggregator\Trap\Handler\Http\ProvidesHttpMiddlewares;
use Buggregator\Trap\Module\Frontend\ProvidesUi;
use Buggregator\Trap\Module\Tracing\Console\Renderer;
use Buggregator\Trap\Module\Tracing\Frame\OtlpTraces;
use Buggregator\Trap\Module\Tracing\Http\OtlpTrap;
use Buggregator\Trap\Proto\ProvidesFrameTypes;
use Buggregator\Trap\Sender\Console\ProvidesRenderers;

/**
 * @internal
 */
final class Module implements ProvidesHttpMiddlewares, ProvidesFrameTypes, ProvidesRenderers, ProvidesUi
{
    #[\Override]
    public function httpMiddlewares(): array
    {
        return [OtlpTrap::class];
    }

    #[\Override]
    public function frameTypes(): array
    {
        return [Frame::TYPE => Frame::class];
    }

    /**
     * The renderer gets the {@see SpanStore} of the container, one for the Trap process.
     */
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
        return [OtlpTraces::class => null];
    }

    #[\Override]
    public function uiEventTypes(): array
    {
        return [];
    }
}
