<?php

declare(strict_types=1);

namespace Buggregator\Trap\Config;

/**
 * @internal
 * @psalm-internal Buggregator
 */
final class SocketServer
{
    /**
     * @param int<1, 65535> $port
     */
    public function __construct(
        public readonly int $port,
        public readonly string $host = '127.0.0.1',
        public readonly string $type = 'tcp',
    ) {
    }
}
