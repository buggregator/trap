<?php

declare(strict_types=1);

namespace Buggregator\Trap\Config\Server;

use Buggregator\Trap\Service\Config\Env;
use Buggregator\Trap\Service\Config\InputOption;

/**
 * Config is a projection of plain TCP ports configuration via ENV and CLI.
 *
 * @internal
 *
 * @psalm-internal Buggregator\Trap
 */
final class TcpPorts
{
    /**
     * List of TCP ports to listen.
     *
     * @var list<int<1, 65535>>
     */
    #[Env('TRAP_TCP_PORTS')]
    #[InputOption('port')]
    public array $ports = [9912];

    /**
     * Host to listen.
     *
     * @var non-empty-string
     */
    #[Env('TRAP_TCP_HOST')]
    public string $host = '127.0.0.1';

    public string $type = 'tcp';
}
