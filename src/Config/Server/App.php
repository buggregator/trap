<?php

declare(strict_types=1);

namespace Buggregator\Trap\Config\Server;

use Buggregator\Trap\Service\Config\Env;

/**
 * Common configuration for the application
 *
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class App
{
    /**
     * Main loop interval in microseconds
     * @var int<50, max>
     */
    #[Env('TRAP_MAIN_LOOP_INTERVAL')]
    public int $mainLoopInterval = 100;
}
