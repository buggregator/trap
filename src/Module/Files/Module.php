<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Files;

use Buggregator\Trap\Server\Sender\ProvidesSenders;

/**
 * @internal
 */
final class Module implements ProvidesSenders
{
    #[\Override]
    public function senders(): array
    {
        return [
            'file' => EventsToFileSender::class,
            'file-body' => BodyToFileSender::class,
        ];
    }
}
