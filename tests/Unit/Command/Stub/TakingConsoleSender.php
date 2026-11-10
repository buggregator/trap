<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Command\Stub;

use Buggregator\Trap\Sender\EventsToFileSender;
use Buggregator\Trap\Sender\ProvidesSenders;

/**
 * Gives a sender under the name of a core one.
 */
final class TakingConsoleSender implements ProvidesSenders
{
    public function senders(): array
    {
        return ['console' => EventsToFileSender::class];
    }
}
