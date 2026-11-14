<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Socket;

use Buggregator\Trap\Server\Socket\Exception\ClientDisconnected;
use Buggregator\Trap\Support\Activity;
use Buggregator\Trap\Support\Timer;
use Internal\Destroy\Destroyable;

/**
 * Client state on the server side.
 *
 * @internal
 */
final class Client implements Destroyable
{
    /**
     * Seconds between checks of a socket nobody wakes the client for: the main loop wakes it when the socket
     * is ready, unless its `socket_select()` fails, with too many sockets for one.
     */
    public const POLL_PERIOD = 0.01;

    /** @var string[] */
    private array $writeQueue = [];

    private bool $toDisconnect = false;

    /** The main loop has seen the socket ready */
    private bool $ready = false;

    /** The send buffer is full: the client doesn't read until it is written */
    private bool $writeBlocked = false;

    private \Closure $onPayload;
    private \Closure $onClose;
    private Timer $selectTimer;

    /**
     * @param positive-int $payloadSize
     */
    private function __construct(
        private readonly \Socket $socket,
        private readonly int $payloadSize,
        float $selectPeriod,
    ) {
        $this->selectTimer = new Timer($selectPeriod);
        \socket_set_nonblock($this->socket);
        $this->setOnPayload(static fn(string $payload) => null);
        $this->setOnClose(static fn() => null);
    }

    /**
     * @param positive-int $payloadSize Max payload size.
     * @param float $selectPeriod Seconds between socket_select() calls the main loop doesn't wake for.
     */
    public static function init(
        \Socket $socket,
        int $payloadSize = 10485760,
        float $selectPeriod = self::POLL_PERIOD,
    ): self {
        return new self(
            socket: $socket,
            payloadSize: $payloadSize,
            selectPeriod: $selectPeriod,
        );
    }

    #[\Override]
    public function destroy(): void
    {
        /** @psalm-suppress RedundantPropertyInitializationCheck */
        isset($this->onClose) and ($this->onClose)();
        try {
            \socket_close($this->socket);
        } catch (\Throwable) {
            // do nothing
        }
        // Unlink all closures and free resources.
        unset($this->onClose, $this->onPayload);
        $this->writeQueue = [];
    }

    public function disconnect(): void
    {
        $this->toDisconnect = true;
    }

    /**
     * Makes the client check its socket on the next resume instead of after the select period.
     */
    public function wake(): void
    {
        $this->ready = true;
    }

    public function getSocket(): \Socket
    {
        return $this->socket;
    }

    /**
     * @return \Socket|null The socket while the client reads it.
     */
    public function readableSocket(): ?\Socket
    {
        return $this->writeBlocked ? null : $this->socket;
    }

    /**
     * @return \Socket|null The socket while the client has data to write.
     */
    public function writableSocket(): ?\Socket
    {
        return $this->writeQueue === [] ? null : $this->socket;
    }

    public function process(): void
    {
        $this->onInit();

        do {
            $progress = $this->ready = false;
            $read = [$this->socket];
            $write = [$this->socket];
            $except = [$this->socket];
            if (\socket_select($read, $write, $except, 0, 0) === false) {
                throw new \RuntimeException('Socket select failed.');
            }

            /** @psalm-suppress RedundantCondition */
            if ($read !== []) {
                $progress = $this->readMessage();
            }

            /** @psalm-suppress RedundantCondition */
            if ($write !== [] && $this->writeQueue !== []) {
                $this->writeQueue();
                $progress = true;
            }

            /** @psalm-suppress RedundantCondition */
            if ($except !== [] || \socket_last_error($this->socket) !== 0) {
                throw new \RuntimeException('Socket exception.');
            }

            if ($this->toDisconnect && $this->writeQueue === []) {
                # Wait for the socket buffer to be flushed.
                // (new Timer(0.005))->wait();

                throw new ClientDisconnected();
            }

            // More data may be waiting: select again on the next iteration
            if ($progress) {
                Activity::report();
                \Fiber::suspend();
                continue;
            }

            // Select again after the period, or once the main loop sees the socket ready or there is data to write
            $this->selectTimer->reset();
            /** @psalm-suppress RedundantCondition The properties change while the fiber is suspended */
            do {
                \Fiber::suspend();
            } while (!$this->ready && $this->writeQueue === [] && !$this->toDisconnect && !$this->selectTimer->isReady());
        } while (true);
    }

    /**
     * @param callable(string): void $callable If non-static callable, it will be bound to the current instance.
     * @psalm-assert callable(string): void $callable
     */
    public function setOnPayload(callable $callable): void
    {
        $closure = $callable(...);
        /** @psalm-suppress PossiblyNullPropertyAssignmentValue, InvalidArgument */
        $this->onPayload = @\Closure::bind($closure, $this) ?? $closure;
    }

    /**
     * @param callable(): void $callable If non-static callable, it will be bound to the current instance.
     * @psalm-assert callable(): void $callable
     */
    public function setOnClose(callable $callable): void
    {
        $closure = $callable(...);
        /** @psalm-suppress PossiblyNullPropertyAssignmentValue, InvalidArgument */
        $this->onClose = @\Closure::bind($closure, $this) ?? $closure;
    }

    public function send(string $payload): void
    {
        if ($this->toDisconnect) {
            return;
        }

        $this->writeQueue[] = $payload;
    }

    public function __destruct()
    {
        $this->destroy();
    }

    protected function onInit(): void {}

    /**
     * @param non-empty-string $payload
     */
    protected function processPayload(string $payload): void
    {
        ($this->onPayload)($payload);
    }

    private function writeQueue(): void
    {
        // The queue is read on each pass: more data can be sent while the fiber is suspended
        while ($this->writeQueue !== []) {
            $data = $this->writeQueue[0];
            $result = @\socket_write($this->socket, $data);
            if ($result === false) {
                $errno = \socket_last_error($this->socket);
                // If the send buffer is temporarily full, suspend the fiber and retry
                if ($errno === \SOCKET_EAGAIN || $errno === \SOCKET_EWOULDBLOCK) {
                    \socket_clear_error($this->socket);
                    $this->writeBlocked = true;
                    \Fiber::suspend();
                    continue;
                }
                // Unrecoverable error, stop writing
                break;
            }

            // The main loop has taken the writable socket
            Activity::report();
            if ($result < \strlen($data)) {
                $this->writeQueue[0] = \substr($data, $result);
            } else {
                \array_shift($this->writeQueue);
            }
        }
        \socket_set_nonblock($this->socket);

        $this->writeBlocked = false;
        $this->writeQueue = [];

        $this->toDisconnect and throw new ClientDisconnected();
    }

    /**
     * Reads what the socket has, up to the payload size.
     *
     * @return bool Whether anything was read.
     */
    private function readMessage(): bool
    {
        $payload = '';
        while (($left = $this->payloadSize - \strlen($payload)) > 0) {
            $data = '';
            $read = @\socket_recv($this->socket, $data, $left, 0);
            // The data is null when the peer has closed the connection
            /** @psalm-suppress TypeDoesNotContainNull */
            if ($read === false || $data === null) {
                $errNo = \socket_last_error($this->socket);
                if ($errNo === \SOCKET_EAGAIN || $errNo === \SOCKET_EWOULDBLOCK) {
                    \socket_clear_error($this->socket);
                    break;
                }

                // The read data goes first: the next iteration fails again
                if ($payload !== '') {
                    break;
                }

                throw new \RuntimeException('Socket read failed [' . $errNo . ']: ' . \socket_strerror($errNo));
            }

            $payload .= $data;
        }

        if ($payload === '') {
            return false;
        }

        $this->processPayload($payload);
        return true;
    }
}
