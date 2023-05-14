<?php

declare(strict_types=1);

namespace Buggregator\Client\Sender;

use Buggregator\Client\Sender;
use Fiber;
use Socket;

class SocketSender implements Sender
{
    private ?Socket $socket = null;

    public function __construct(
        private string $host,
        private int $port,
    ) {
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    public function process(): void
    {
    }

    public function send(string $data): void
    {
        $this->connect();
        $lastBytes = \strlen($data);

        while ($lastBytes > 0) {
            $write = [$this->socket];
            $read = $except = null;
            $result = $this->checkError(\socket_select($read, $write, $except, 0, 0));
            if ($result === 0) {
                Fiber::suspend();
                continue;
            }

            $lastBytes -= $this->checkError(\socket_write($this->socket, \substr($data, -$lastBytes), $lastBytes));
        }
    }

    /**
     * @psalm-assert !null $this->socket
     */
    public function connect(): void
    {
        if ($this->socket !== null) {
            return;
        }

        $this->socket = $this->checkError(\socket_create(\AF_INET, \SOCK_STREAM, \SOL_TCP));
        $this->checkError(\socket_set_nonblock($this->socket));
        $this->checkError(\socket_connect($this->socket, $this->host, $this->port));
    }

    public function disconnect(): void
    {
        if ($this->socket !== null) {
            \socket_close($this->socket);
        }
    }

    /**
     * @template T
     *
     * @param T|false $value
     *
     * @psalm-assert !false $value
     * @return T
     */
    private function checkError(mixed $value): mixed
    {
        if ($value === false) {
            throw new \RuntimeException('Socket error: reason: ' . \socket_strerror(\socket_last_error()));
        }
        return $value;
    }
}
