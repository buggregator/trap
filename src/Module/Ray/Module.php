<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Ray;

use Buggregator\Trap\Handler\Http\ProvidesHttpMiddlewares;
use Buggregator\Trap\Module\Ray\Http\RayTrap;

/**
 * @internal
 */
final class Module implements ProvidesHttpMiddlewares
{
    #[\Override]
    public function httpMiddlewares(): array
    {
        return [RayTrap::class];
    }
}
