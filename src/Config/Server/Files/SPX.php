<?php

declare(strict_types=1);

namespace Buggregator\Trap\Config\Server\Files;

use Buggregator\Trap\Application\Config\Internal\Attribute\InflectableConfig;
use Buggregator\Trap\Application\Config\Internal\Attribute\PhpIni;

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
