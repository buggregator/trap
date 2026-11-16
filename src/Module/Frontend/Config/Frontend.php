<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Config;

use Buggregator\Trap\Server\Application\Config\Internal\Attribute\Env;
use Buggregator\Trap\Server\Application\Config\Internal\Attribute\InflectableConfig;
use Buggregator\Trap\Server\Application\Config\Internal\Attribute\InputOption;
use Buggregator\Trap\Server\Application\Config\Internal\Attribute\XPath;
use Buggregator\Trap\Server\Sender\HttpEndpoint;

/**
 * @internal
 */
#[InflectableConfig]
final class Frontend
{
    /** @var int<1, 65535> */
    #[Env('TRAP_UI_PORT')]
    #[InputOption('ui')]
    #[XPath('/trap/frontend/@port')]
    public int $port = 8000;

    /** @var non-empty-string|null Null when not set, see {@see HttpEndpoint::__construct()}. */
    #[Env('TRAP_UI_HOST')]
    #[XPath('/trap/frontend/@host')]
    public ?string $host = null;
}
