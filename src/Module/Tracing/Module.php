<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Tracing;

use Buggregator\Trap\Module\Console\ProvidesRenderers;
use Buggregator\Trap\Module\Mcp\ProvidesMcpTools;
use Buggregator\Trap\Module\Tracing\Console\Renderer;
use Buggregator\Trap\Module\Tracing\Console\SignalRenderer;
use Buggregator\Trap\Module\Tracing\Frame\OtlpLogs;
use Buggregator\Trap\Module\Tracing\Frame\OtlpMetrics;
use Buggregator\Trap\Module\Tracing\Frame\OtlpTraces;
use Buggregator\Trap\Module\Tracing\Frontend\TelemetryMapper;
use Buggregator\Trap\Module\Tracing\Http\OtlpTrap;
use Buggregator\Trap\Module\Tracing\Mcp\GetTrace;
use Buggregator\Trap\Module\Tracing\Mcp\ListTraces;
use Buggregator\Trap\Server\Event\ProvidesEvents;
use Buggregator\Trap\Server\Handler\Http\ProvidesHttpMiddlewares;
use Buggregator\Trap\Server\Proto\ProvidesFrameTypes;

/**
 * The mapper fills the {@see \Buggregator\Trap\Server\Telemetry\TelemetryStore} the web UI and the MCP tools
 * read; the console renderer keeps a {@see Console\SpanBuffer} of its own.
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
        return [Renderer::class, SignalRenderer::class];
    }

    /**
     * OTLP makes no events: the mapper fills the telemetry store.
     */
    #[\Override]
    public function eventMappers(): array
    {
        return [
            OtlpTraces::class => TelemetryMapper::class,
            OtlpLogs::class => TelemetryMapper::class,
            OtlpMetrics::class => TelemetryMapper::class,
        ];
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
