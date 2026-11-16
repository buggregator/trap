<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Tracing;

use Buggregator\Trap\Module\Console\ProvidesRenderers;
use Buggregator\Trap\Module\Mcp\ProvidesMcpTools;
use Buggregator\Trap\Module\Tracing\Console\Renderer;
use Buggregator\Trap\Module\Tracing\Frame\OtlpTraces;
use Buggregator\Trap\Module\Tracing\Http\OtlpTrap;
use Buggregator\Trap\Module\Tracing\Mcp\GetTrace;
use Buggregator\Trap\Module\Tracing\Mcp\ListTraces;
use Buggregator\Trap\Server\Event\ProvidesEvents;
use Buggregator\Trap\Server\Handler\Http\ProvidesHttpMiddlewares;
use Buggregator\Trap\Server\Proto\ProvidesFrameTypes;

/**
 * The middleware and the tools share the {@see SpanStore} of the container, one for the Trap process:
 * the middleware fills it while the MCP server runs, the tools read it. The renderer keeps a store of its own.
 *
 * @internal
 */
final class Module implements
    ProvidesHttpMiddlewares,
    ProvidesFrameTypes,
    ProvidesRenderers,
    ProvidesEvents,
    ProvidesMcpTools
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

    #[\Override]
    public function renderers(): array
    {
        return [Renderer::class];
    }

    /**
     * Traces make no events: the tools read the span store.
     */
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

    #[\Override]
    public function mcpTools(): array
    {
        return [ListTraces::class, GetTrace::class];
    }
}
