<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Monolog;

use Buggregator\Trap\Module\Monolog\Traffic\Dispatcher;
use Buggregator\Trap\Traffic\ProvidesDispatchers;

/**
 * @internal
 */
final class Module implements ProvidesDispatchers
{
    #[\Override]
    public function dispatchers(): array
    {
        return [Dispatcher::class];
    }
}
