<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Tracing;

use Buggregator\Trap\Handler\Http\ProvidesHttpMiddlewares;
use Buggregator\Trap\Module\Tracing\Http\OtlpTrap;

/**
 * @internal
 */
final class Module implements ProvidesHttpMiddlewares
{
    #[\Override]
    public function httpMiddlewares(): array
    {
        return [OtlpTrap::class];
    }
}
