<?php

declare(strict_types=1);

namespace Buggregator\Trap\Config\Server;

use Buggregator\Trap\Service\Config\InputOption;
use Buggregator\Trap\Service\Config\Env;
use Buggregator\Trap\Service\Config\XPath;

/**
 * @internal
 */
final class Frontend
{
    /** @var int<1, 65535> */
    #[Env('TRAP_UI_PORT')]
    #[InputOption('ui')]
    #[XPath('/trap/frontend/@port')]
    public int $port = 8000;

    /** @var non-empty-string */
    #[Env('TRAP_UI_HOST')]
    #[XPath('/trap/frontend/@host')]
    public string $host = '127.0.0.1';
}
