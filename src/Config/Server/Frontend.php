<?php

declare(strict_types=1);

namespace Buggregator\Trap\Config\Server;

use Buggregator\Trap\Service\Config\CliOption;
use Buggregator\Trap\Service\Config\Env;
use Buggregator\Trap\Service\Config\XPath;

/**
 * @internal
 */
final class Frontend
{
    /** @var int<1, max> */
    #[Env('TRAP_FRONTEND_PORT')]
    #[CliOption('ui')]
    #[XPath('/trap/frontend/@port')]
    public int $port = 8000;
}
