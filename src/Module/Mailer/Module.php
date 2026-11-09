<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mailer;

use Buggregator\Trap\Module\Frontend\ProvidesUi;
use Buggregator\Trap\Module\Mailer\Console\Renderer;
use Buggregator\Trap\Module\Mailer\Frontend\ApiController;
use Buggregator\Trap\Module\Mailer\Frontend\AssetsController;
use Buggregator\Trap\Module\Mailer\Frontend\Mapper;
use Buggregator\Trap\Module\Mailer\Sender\MailToFileSender;
use Buggregator\Trap\Module\Mailer\Traffic\Dispatcher;
use Buggregator\Trap\Proto\ProvidesFrameTypes;
use Buggregator\Trap\Sender\Console\ProvidesRenderers;
use Buggregator\Trap\Sender\ProvidesSenders;
use Buggregator\Trap\Traffic\ProvidesDispatchers;

/**
 * @internal
 */
final class Module implements
    ProvidesDispatchers,
    ProvidesFrameTypes,
    ProvidesRenderers,
    ProvidesSenders,
    ProvidesUi
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
    public function senders(): array
    {
        return ['mail-to-file' => MailToFileSender::class];
    }

    #[\Override]
    public function uiApiControllers(): array
    {
        return [ApiController::class];
    }

    #[\Override]
    public function uiAssetsControllers(): array
    {
        return [AssetsController::class];
    }

    #[\Override]
    public function uiEventMappers(): array
    {
        return [Frame::class => Mapper::class];
    }

    #[\Override]
    public function uiEventTypes(): array
    {
        return ['smtp'];
    }
}
