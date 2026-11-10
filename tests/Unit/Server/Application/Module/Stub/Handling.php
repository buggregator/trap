<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Application\Module\Stub;

use Buggregator\Trap\Server\Handler\Http\ProvidesHttpMiddlewares;

final class Handling implements ProvidesHttpMiddlewares
{
    public function httpMiddlewares(): array
    {
        return [];
    }
}
