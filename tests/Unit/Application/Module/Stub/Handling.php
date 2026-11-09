<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Application\Module\Stub;

use Buggregator\Trap\Handler\Http\ProvidesHttpMiddlewares;

final class Handling implements ProvidesHttpMiddlewares
{
    public function httpMiddlewares(): array
    {
        return [];
    }
}
