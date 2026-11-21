<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Sender;

use Buggregator\Trap\Server\Config\SocketServer;

/**
 * A sender that needs Trap to listen on one more TCP port. The port takes every protocol, as the others do;
 * the sender's module recognizes its own traffic with a dispatcher.
 *
 * @internal
 */
interface ListensTcp
{
    /**
     * @param non-empty-string $host The host of the TCP ports, `TRAP_TCP_HOST`.
     */
    public function tcpServer(string $host): SocketServer;
}
