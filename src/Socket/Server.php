<?php

declare(strict_types=1);

namespace Buggregator\Client\Socket;

use Buggregator\Client\Logger;
use Buggregator\Client\Socket\Exception\DisconnectClient;
use Closure;
use Fiber;
use RuntimeException;
use Socket;

class Server
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
    ) {
        $this->socket = \socket_create_listen($port);
        if ($this->socket === false) {
            throw new \RuntimeException('Socket create failed.');
        }
        \socket_set_nonblock($this->socket);

        Logger::info('Server started on 127.0.0.1:%s', $port);
    }

    public function __destruct()
    {
        try {
            \socket_close($this->socket);
        } finally {
            foreach ($this->clients as $client) {
                $client->__destruct();
            }
            unset($this->socket, $this->clients, $this->fibers);
        }
    }

    /**
     * @param positive-int $port
     * @param positive-int $payloadSize Max payload size.
     * @param null|\Closure(Client, int $id): void $clientInflector
     */
    public static function init(
        int $port = 9912,
        int $payloadSize = 10485760,
        ?Closure $clientInflector = null,
    ): self {
        return new self($port, $payloadSize, $clientInflector);
    }

    public function process(): void
    {
        $socket = @\socket_accept($this->socket);
        if ($socket !== false) {
            $key = (int)\array_key_last($this->clients) + 1;
            $client = null;
            try {
                $client = Client::init($socket, $this->payloadSize);
                $this->clients[$key] = $client;
                $this->clientInflector !== null and ($this->clientInflector)($client, $key);
                $this->fibers[$key] = new Fiber($client->process(...));
            } catch (\Throwable) {
                $client?->__destruct();
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
                    Logger::info('Custom disconnect.');
                }
                $this->clients[$key]->__destruct();
                // Logger::exception($e, 'Client fiber.');
                unset($this->clients[$key], $this->fibers[$key]);
            }
        }
    }
}
