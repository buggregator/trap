<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Config;

use Buggregator\Trap\Server\Application\Config\Internal\Attribute\Env;
use Buggregator\Trap\Server\Application\Config\Internal\Attribute\InflectableConfig;

/**
 * The Xdebug debugger turned on without the `--xdebug` option, by the variable of Buggregator Server.
 *
 * @internal
 * @psalm-internal Buggregator\Trap
 */
#[InflectableConfig]
final class XDebugProxy
{
    /** `true`, `1`, `yes` or `on` turns the debugger on, as `--xdebug` does */
    #[Env('XDEBUG_PROXY_ENABLED')]
    public bool $enabled = false;
}
