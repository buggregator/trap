<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Sentry;

use Buggregator\Trap\Module\Frontend\ProvidesUi;
use Buggregator\Trap\Module\Sentry\Console\SentryEnvelope as EnvelopeRenderer;
use Buggregator\Trap\Module\Sentry\Console\SentryStore as StoreRenderer;
use Buggregator\Trap\Module\Sentry\Frame\SentryEnvelope;
use Buggregator\Trap\Module\Sentry\Frame\SentryStore;
use Buggregator\Trap\Module\Sentry\Frontend\EnvelopeMapper;
use Buggregator\Trap\Module\Sentry\Frontend\StoreMapper;
use Buggregator\Trap\Module\Sentry\Http\SentryTrap;
use Buggregator\Trap\Server\Handler\Http\ProvidesHttpMiddlewares;
use Buggregator\Trap\Server\Proto\ProvidesFrameTypes;
use Buggregator\Trap\Server\Sender\Console\ProvidesRenderers;

/**
 * @internal
 */
final class Module implements ProvidesHttpMiddlewares, ProvidesFrameTypes, ProvidesRenderers, ProvidesUi
{
    #[\Override]
    public function httpMiddlewares(): array
    {
        return [SentryTrap::class];
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
        return [
            SentryStore::class => StoreMapper::class,
            SentryEnvelope::class => EnvelopeMapper::class,
        ];
    }

    #[\Override]
    public function uiEventTypes(): array
    {
        return ['sentry'];
    }
}
