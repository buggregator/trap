<?php

declare(strict_types=1);

namespace Buggregator\Client\Sender;

use Buggregator\Client\Logger;
use Buggregator\Client\Proto\Frame;
use Buggregator\Client\Proto\Timer;
use Buggregator\Client\Sender;
use Fiber;
use RuntimeException;
use Socket;

class SocketSender implements Sender
{
    private ?Socket $socket = null;
    private Timer $timer;

    public function __construct(
        private string $host,
        private int $port,
        float $reconnectTimeout = 1.0,
    ) {
        $this->timer = (new Timer(
            beep: $reconnectTimeout,
            condition: fn(): bool => $this->socket !== null,
        ))->stop();
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    public function process(): void
    {
    }

    public function send(iterable $frames): void
    {
        $data = '[' . \implode(',', \array_map(
            static fn (Frame $frame): string => $frame->__toString(),
            \is_array($frames) ? $frames : \iterator_to_array($frames),
        )) . ']';

        $lastBytes = \strlen($data);

        while ($lastBytes > 0) {
            try {
                $this->connect();
                $write = [$this->socket];
                $read = $except = null;
                $result = $this->checkError(\socket_select($read, $write, $except, 0, 0));
                if ($result === 0) {
                    Fiber::suspend();
                    continue;
                }

                $lastBytes -= $this->checkError(\socket_write($this->socket, \substr($data, -$lastBytes), $lastBytes));
            } catch (\Throwable $e) {
                Logger::error('SocketSender error: %s', $e->getLine(), $e->getMessage());
                $this->disconnect();
            }
        }
    }

    /**
     * @psalm-assert !null $this->socket
     */
    public function connect(): void
    {
        do {
            if ($this->socket !== null) {
                return;
            }

            try {
                $this->timer->isStopped() or throw new RuntimeException('wait for reconnect');

                Logger::info('Connecting to %s:%d', $this->host, $this->port);
                $this->socket = $this->checkError(\socket_create(\AF_INET, \SOCK_STREAM, \SOL_TCP));
                $this->checkError(\socket_connect($this->socket, $this->host, $this->port));
                $this->checkError(\socket_set_nonblock($this->socket));
                return;
            } catch (\Throwable $e) {
                Logger::error('SocketSender Connection error: %s', $e->getLine(), $e->getMessage());

                $this->socket = null;
                $this->timer->continue()->wait()->stop();
            }
        } while (true);
    }

    public function disconnect(): void
    {
        try {
            if ($this->socket !== null) {
                \socket_close($this->socket);
            }
        } catch (\Throwable) {
            // Do nothing.
        } finally {
            $this->socket = null;
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
