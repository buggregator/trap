<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\XDebug\Sender;

use Buggregator\Trap\Module\XDebug\Debugger;
use Buggregator\Trap\Server\Config\SocketServer;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Server\Sender;
use Buggregator\Trap\Server\Sender\ListensTcp;

/**
 * The `--xdebug` option: turns the debugger on and listens on its port.
 *
 * @internal
 */
final class DebuggerSender implements Sender, ListensTcp
{
    /**
     * Created only when the debugger is chosen, so being created is what turns it on.
     */
    public function __construct(
        private readonly Debugger $debugger,
        private readonly Logger $logger,
    ) {
        $debugger->enable();
    }

    /**
     * Sessions make no frames.
     */
    #[\Override]
    public function send(iterable $frames): void {}

    #[\Override]
    public function tcpServer(string $host): SocketServer
    {
        $config = $this->debugger->config;
        $host = $config->host ?? $host;
        $this->logger->info(
            'Xdebug debugger on %s (xdebug.client_port), mode %s, IDE at %s.',
            "$host:{$config->port}",
            $config->mode(),
            $config->ideAddr,
        );

        return new SocketServer($config->port, $host);
    }
}
