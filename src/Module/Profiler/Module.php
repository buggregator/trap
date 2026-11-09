<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler;

use Buggregator\Trap\Handler\Http\ProvidesHttpMiddlewares;
use Buggregator\Trap\Module\Profiler\Config\SPX;
use Buggregator\Trap\Module\Profiler\Config\XDebug;
use Buggregator\Trap\Module\Profiler\Config\XHProf;
use Buggregator\Trap\Module\Profiler\Http\XDebugTrap;
use Buggregator\Trap\Module\Profiler\Http\XHProfTrap;
use Buggregator\Trap\Service\FilesObserver\ProvidesFileObservers;

/**
 * @internal
 */
final class Module implements ProvidesHttpMiddlewares, ProvidesFileObservers
{
    #[\Override]
    public function httpMiddlewares(): array
    {
        return [XHProfTrap::class, XDebugTrap::class];
    }

    #[\Override]
    public function fileObserverConfigs(): array
    {
        return [XHProf::class, XDebug::class, SPX::class];
    }
}
