<?php

declare(strict_types=1);

namespace Buggregator\Trap\Socket;

use Buggregator\Trap\Logger;
use Buggregator\Trap\Processable;
use Buggregator\Trap\Socket\Exception\DisconnectClient;
use Closure;
use Fiber;
use RuntimeException;
use Socket;

/**
 * @internal
 */
final class Server implements Processable
{
    /** @var false|resource|Socket */
    private $socket;

    /** @var array<int, Client> */
    private array $clients = [];

    /** @var array<int, Fiber> */
    private array $fibers = [];

    /**
     * @param null|Closure(Client, int $id): void $clientInflector
     * @param positive-int $payloadSize Max payload size.
     */
    private function __construct(
        int $port,
        private readonly int $payloadSize,
        private readonly ?Closure $clientInflector,
        private readonly Logger $logger,
    ) {
        $this->socket = @\socket_create_listen($port);
        /** @link https://github.com/buggregator/trap/pull/14 */
        \socket_set_option($this->socket, \SOL_SOCKET, \SO_LINGER, ['l_linger' => 0, 'l_onoff' => 1]);

        if ($this->socket === false) {
            throw new \RuntimeException('Socket create failed.');
        }
        \socket_set_nonblock($this->socket);

        $logger->status('Application', 'Server started on 127.0.0.1:%s', $port);
    }

    public function __destruct()
    {
        try {
            \socket_close($this->socket);
        } finally {
            foreach ($this->clients as $client) {
                $client->close();
            }
            unset($this->socket, $this->clients, $this->fibers);
        }
    }

    /**
     * @param int<1, 65535> $port
     * @param positive-int $payloadSize Max payload size.
     * @param null|\Closure(Client, int $id): void $clientInflector
     */
    public static function init(
        int $port = 9912,
        int $payloadSize = 10485760,
        ?Closure $clientInflector = null,
        Logger $logger = new Logger(),
    ): self {
        return new self($port, $payloadSize, $clientInflector, $logger);
    }

    public function process(): void
    {
        while (false !== ($socket = \socket_accept($this->socket))) {
            $client = null;
            try {
                $client = Client::init($socket, $this->payloadSize);
                $key = (int)\array_key_last($this->clients) + 1;
                $this->clients[$key] = $client;
                $this->clientInflector !== null and ($this->clientInflector)($client, $key);
                $this->fibers[$key] = new Fiber($client->process(...));
            } catch (\Throwable) {
                $client?->close();
                unset($client, $this->clients[$key], $this->fibers[$key]);
            }
        }

        foreach ($this->fibers as $key => $fiber) {
            try {
                $fiber->isStarted() ? $fiber->resume() : $fiber->start();

                if ($fiber->isTerminated()) {
                    throw new RuntimeException('Client terminated.');
                }
            } catch (\Throwable $e) {
                if ($e instanceof DisconnectClient) {
                    $this->logger->info('Custom disconnect.');
                }
                $this->clients[$key]->close();
                // Logger::exception($e, 'Client fiber.');
                unset($this->clients[$key], $this->fibers[$key]);
            }
        }
    }
}
