<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Application\Module\Stub;

use Buggregator\Trap\Handler\Http\ProvidesHttpMiddlewares;
use Buggregator\Trap\Traffic\ProvidesDispatchers;

final class DispatchingAndHandling implements ProvidesDispatchers, ProvidesHttpMiddlewares
{
    public function dispatchers(): array
    {
        return [];
    }

    public function httpMiddlewares(): array
    {
        return [];
    }
}
