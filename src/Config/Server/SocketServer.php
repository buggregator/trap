<?php

declare(strict_types=1);

namespace Buggregator\Trap\Config\Server;

/**
 * @internal
 * @psalm-internal Buggregator
 */
final class SocketServer
{
    /**
     * @param int<1, 65535> $port
     * @param int<50, max> $pollingInterval Time to wait between socket_accept() and socket_select() calls
     *        in microseconds.
     */
    public function __construct(
        public readonly int $port,
        public readonly string $host = '127.0.0.1',
        public readonly string $type = 'tcp',
        public int $pollingInterval = 1_000,
    ) {}
}
