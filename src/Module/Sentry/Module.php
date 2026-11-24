<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Sentry;

use Buggregator\Trap\Module\Console\ProvidesRenderers;
use Buggregator\Trap\Module\Frontend\ProvidesUi;
use Buggregator\Trap\Module\Mcp\ProvidesEventViews;
use Buggregator\Trap\Module\Mcp\ProvidesMcpTools;
use Buggregator\Trap\Module\Sentry\Console\SentryEnvelope as EnvelopeRenderer;
use Buggregator\Trap\Module\Sentry\Console\SentryStore as StoreRenderer;
use Buggregator\Trap\Module\Sentry\Frame\SentryEnvelope;
use Buggregator\Trap\Module\Sentry\Frame\SentryStore;
use Buggregator\Trap\Module\Sentry\Frontend\ApiController;
use Buggregator\Trap\Module\Sentry\Frontend\ClearController;
use Buggregator\Trap\Module\Sentry\Frontend\CronController;
use Buggregator\Trap\Module\Sentry\Frontend\EnvelopeMapper;
use Buggregator\Trap\Module\Sentry\Frontend\QueueController;
use Buggregator\Trap\Module\Sentry\Frontend\StoreMapper;
use Buggregator\Trap\Module\Sentry\Http\CronTrap;
use Buggregator\Trap\Module\Sentry\Http\SentryTrap;
use Buggregator\Trap\Module\Sentry\Mcp\ListCrons;
use Buggregator\Trap\Module\Sentry\Mcp\ListFeedback;
use Buggregator\Trap\Module\Sentry\Mcp\ListQueueJobs;
use Buggregator\Trap\Module\Sentry\Mcp\SentryView;
use Buggregator\Trap\Server\Event\ProvidesEvents;
use Buggregator\Trap\Server\Handler\Http\ProvidesHttpMiddlewares;
use Buggregator\Trap\Server\Proto\ProvidesFrameTypes;

/**
 * @internal
 */
final class Module implements
    ProvidesHttpMiddlewares,
    ProvidesFrameTypes,
    ProvidesRenderers,
    ProvidesEvents,
    ProvidesEventViews,
    ProvidesMcpTools,
    ProvidesUi
{
    #[\Override]
    public function httpMiddlewares(): array
    {
        return [SentryTrap::class, CronTrap::class];
    }

    #[\Override]
    public function frameTypes(): array
    {
        return [Frame::TYPE => Frame::class];
    }

    #[\Override]
    public function renderers(): array
    {
        return [StoreRenderer::class, EnvelopeRenderer::class];
    }

    #[\Override]
    public function eventMappers(): array
    {
        return [
            SentryStore::class => StoreMapper::class,
            SentryEnvelope::class => EnvelopeMapper::class,
        ];
    }

    #[\Override]
    public function eventTypes(): array
    {
        return ['sentry'];
    }

    #[\Override]
    public function eventViews(): array
    {
        return [SentryView::class];
    }

    #[\Override]
    public function mcpTools(): array
    {
        return [ListFeedback::class, ListQueueJobs::class, ListCrons::class];
    }

    #[\Override]
    public function uiApiControllers(): array
    {
        return [ClearController::class, ApiController::class, QueueController::class, CronController::class];
    }

    #[\Override]
    public function uiAssetsControllers(): array
    {
        return [];
    }
}
