<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Tracing;

use Buggregator\Trap\Module\Console\ProvidesRenderers;
use Buggregator\Trap\Module\Tracing\Console\Renderer;
use Buggregator\Trap\Module\Tracing\Frame\OtlpTraces;
use Buggregator\Trap\Module\Tracing\Http\OtlpTrap;
use Buggregator\Trap\Server\Event\ProvidesEvents;
use Buggregator\Trap\Server\Handler\Http\ProvidesHttpMiddlewares;
use Buggregator\Trap\Server\Proto\ProvidesFrameTypes;

/**
 * @internal
 */
final class Module implements ProvidesHttpMiddlewares, ProvidesFrameTypes, ProvidesRenderers, ProvidesEvents
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
    public function eventMappers(): array
    {
        return [OtlpTraces::class => null];
    }

    #[\Override]
    public function eventTypes(): array
    {
        return [];
    }
}
