<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\Config;

use Buggregator\Trap\Server\Application\Config\Internal\Attribute\InflectableConfig;
use Buggregator\Trap\Server\Application\Config\Internal\Attribute\PhpIni;
use Buggregator\Trap\Server\Config\Files\ObserverConfig;

/**
 * @internal
 */
#[InflectableConfig]
final class SPX extends ObserverConfig
{
    /** @var non-empty-string|null Path to SPX files */
    #[PhpIni('spx.data_dir')]
    public ?string $path = null;
}
