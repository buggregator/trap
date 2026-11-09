<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\VarDumper;

use Buggregator\Trap\Module\VarDumper\Traffic\Dispatcher;
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
