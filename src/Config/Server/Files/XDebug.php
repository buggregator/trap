<?php

declare(strict_types=1);

namespace Buggregator\Trap\Config\Server\Files;

use Buggregator\Trap\Service\Config\PhpIni;

/**
 * @internal
 */
final class XDebug extends ObserverConfig
{
    /** @var non-empty-string|null Path to XDebug files */
    #[PhpIni('xdebug.output_dir')]
    public ?string $path = null;
}
