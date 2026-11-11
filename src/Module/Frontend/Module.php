<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend;

use Buggregator\Trap\Module\Frontend\Sender\FrontendSender;
use Buggregator\Trap\Server\Sender\ProvidesSenders;

/**
 * @internal
 */
final class Module implements ProvidesSenders
{
    /**
     * The `--ui` option of the `run` command chooses the `ui` sender.
     */
    #[\Override]
    public function senders(): array
    {
        return ['ui' => FrontendSender::class];
    }
}
