<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Application\Module\Stub;

use Buggregator\Trap\Server\Handler\Http\ProvidesHttpMiddlewares;
use Buggregator\Trap\Server\Traffic\ProvidesDispatchers;

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
