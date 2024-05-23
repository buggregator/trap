<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender;

use Buggregator\Trap\Logger;
use Buggregator\Trap\Processable;
use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Sender;
use Buggregator\Trap\Support\Json;
use Buggregator\Trap\Support\Timer;
use Fiber;
use Socket;

/**
 * @internal
 */
abstract class SocketSender implements Sender, Processable
{
    private ?\Socket $socket = null;

    /** @var Timer Reconnect timer */
    private Timer $timer;

    /** Current data transaction Fiber */
    private ?\Fiber $handler = null;

    /** @var \SplQueue<iterable<array-key, Frame>> */
    private \SplQueue $queue;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        float $reconnectTimeout = 1.0,
        private readonly ?Logger $logger = null,
    ) {
        $this->queue = new \SplQueue();
        $this->timer = (new Timer(
            beep: $reconnectTimeout,
            condition: fn(): bool => $this->socket !== null,
        ))->stop();
    }

    public function process(): void
    {
        if ($this->handler !== null) {
            try {
                if ($this->handler->isTerminated()) {
                    $this->handler = null;
                } else {
                    $this->handler->resume();
                }
            } catch (\Throwable $e) {
                $this->logger?->exception($e, 'SocketSender error');
                $this->disconnect();
            }
        }
        if ($this->handler === null && !$this->queue->isEmpty()) {
            $this->handler = new \Fiber([$this, 'sendNext']);
            $this->handler->start();
        }
    }

    public function send(iterable $frames): void
    {
        $this->queue->enqueue($frames);
    }

    public function sendNext(): void
    {
        $data = $this->makePackage($this->preparePayload($this->queue[0]));
        $lastBytes = \strlen($data);
        try {
            $this->connect();
            while ($lastBytes > 0) {
                $write = [$this->socket];
                $read = $except = null;
                $result = $this->checkError(\socket_select($read, $write, $except, 0, 0));
                if ($result === 0) {
                    \Fiber::suspend();
                    continue;
                }

                $lastBytes -= $this->checkError(\socket_write($this->socket, \substr($data, -$lastBytes), $lastBytes));
            }
            $this->queue->dequeue();
        } catch (\Throwable $e) {
            $this->logger?->info('SocketSender error: %s', $e->getLine(), $e->getMessage());
            $this->disconnect();
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    abstract protected function makePackage(string $payload): string;

    /**
     * @param iterable<array-key, Frame> $frames
     */
    protected function preparePayload(iterable $frames): string
    {
        return '[' . \implode(',', \array_map(
            static fn(Frame $frame): string => Json::encode($frame),
            \is_array($frames) ? $frames : \iterator_to_array($frames),
        )) . ']';
    }

    /**
     * @psalm-assert !null $this->socket
     */
    protected function connect(): void
    {
        do {
            if ($this->socket !== null) {
                return;
            }

            try {
                $this->timer->isStopped() or throw new \RuntimeException('wait for reconnect');

                $this->logger?->info('Connecting to %s:%d', $this->host, $this->port);
                $this->socket = $this->checkError(\socket_create(\AF_INET, \SOCK_STREAM, \SOL_TCP));
                $this->checkError(\socket_connect($this->socket, $this->host, $this->port));
                $this->checkError(\socket_set_nonblock($this->socket));
                return;
            } catch (\Throwable $e) {
                $this->logger?->info('SocketSender Connection error: %s', $e->getLine(), $e->getMessage());

                $this->socket = null;
                $this->timer->continue()->wait()->stop();
            }
        } while (true);
    }

    protected function disconnect(): void
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
    protected function checkError(mixed $value): mixed
    {
        if ($value === false) {
            throw new \RuntimeException('Socket error: reason: ' . \socket_strerror(\socket_last_error()));
        }
        return $value;
    }
}
