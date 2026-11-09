<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Sentry;

use Buggregator\Trap\Handler\Http\ProvidesHttpMiddlewares;
use Buggregator\Trap\Module\Sentry\Http\SentryTrap;

/**
 * @internal
 */
final class Module implements ProvidesHttpMiddlewares
{
    #[\Override]
    public function httpMiddlewares(): array
    {
        return [SentryTrap::class];
    }
}
