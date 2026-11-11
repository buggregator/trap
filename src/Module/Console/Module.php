<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Console;

use Buggregator\Trap\Server\Sender\ProvidesSenders;

/**
 * @internal
 */
final class Module implements ProvidesSenders
{
    #[\Override]
    public function senders(): array
    {
        return ['console' => ConsoleSender::class];
    }
}
