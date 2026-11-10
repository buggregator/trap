<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Config;

use Buggregator\Trap\Server\Application\Config\Internal\Attribute\Env;
use Buggregator\Trap\Server\Application\Config\Internal\Attribute\InflectableConfig;

/**
 * Common configuration for the application
 *
 * @internal
 * @psalm-internal Buggregator\Trap
 */
#[InflectableConfig]
final class App
{
    /**
     * Main loop interval in microseconds
     * @var int<50, max>
     */
    #[Env('TRAP_MAIN_LOOP_INTERVAL')]
    public int $mainLoopInterval = 100;
}
