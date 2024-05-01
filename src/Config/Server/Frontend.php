<?php

declare(strict_types=1);

namespace Buggregator\Trap\Config\Server;

use Buggregator\Trap\Service\Config\XPath;

/**
 * @internal
 */
final class Frontend
{
    /** @var int<1, max> */
    #[XPath('/trap/frontend@port')]
    public int $port = 8000;
}
