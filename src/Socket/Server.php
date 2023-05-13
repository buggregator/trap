<?php

declare(strict_types=1);

namespace Buggregator\Client\Socket;

use Closure;
use Fiber;
use RuntimeException;
use Socket;

class Server
{
    /** @var false|resource|Socket */
    private $socket;

    /** @var Client[] */
    private array $clients = [];

    /** @var Fiber[] */
    private array $fibers = [];

    /**
     * @param null|Closure(Client, int $id): void $clientInflector
     */
    private function __construct(
        int $port,
        private readonly int $payloadSize,
        private readonly bool $binary,
        private readonly ?Closure $clientInflector,
    ) {
        $this->socket = \socket_create_listen($port);
        if ($this->socket === false) {
            throw new \RuntimeException('Socket create failed.');
        }
        \socket_set_nonblock($this->socket);

        echo "Server started on 127.0.0.1:$port\n";
    }

    public function __destruct()
    {
        \socket_close($this->socket);
    }

    /**
     * @param positive-int $port
     * @param bool $binary If {@see false}, \r \n \0 boundaries will be respected.
     * @param positive-int $payloadSize Max payload size.
     * @param null|\Closure(Client, int $id): void $clientInflector
     */
    public static function init(
        int $port = 9912,
        int $payloadSize = 10485760,
        bool $binary = true,
        ?Closure $clientInflector = null,
    ): self {
        return new self($port, $payloadSize, $binary, $clientInflector);
    }

    public function process(): void
    {
        $socket = \socket_accept($this->socket);
        if ($socket !== false) {
            $key = \array_key_last($this->clients) + 1;
            try {
                $client = Client::init($socket, $this->payloadSize, $this->binary);
                $this->clients[$key] = $client;
                $this->clientInflector !== null and ($this->clientInflector)($client, $key);
                $this->fibers[$key] = new Fiber($client->process(...));
            } catch (\Throwable) {
                unset($client, $this->clients[$key], $this->fibers[$key]);
            }
        }

        foreach ($this->fibers as $key => $fiber) {
            try {
                $fiber->isStarted() ? $fiber->resume() : $fiber->start();

                if ($fiber->isTerminated()) {
                    throw new RuntimeException('Client terminated.');
                }
            } catch (\Throwable) {
                unset($this->clients[$key], $this->fibers[$key]);
            }
        }
    }
}
