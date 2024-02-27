<?php

declare(strict_types=1);

namespace Buggregator\Trap\Socket;

use Buggregator\Trap\Destroyable;
use Buggregator\Trap\Socket\Exception\ClientDisconnected;
use Buggregator\Trap\Support\Timer;
use Fiber;

/**
 * Client state on the server side.
 *
 * @internal
 */
final class Client implements Destroyable
{
     /** @var string[] */
    private array $writeQueue = [];

    /** @var string */
    private string $readBuffer = '';

    private bool $toDisconnect = false;
    private \Closure $onPayload;
    private \Closure $onClose;

    private function __construct(
        private readonly \Socket $socket,
        private readonly int $payloadSize,
    ) {
        \socket_set_nonblock($this->socket);
        $this->setOnPayload(fn(string $payload) => null);
        $this->setOnClose(fn() => null);
    }

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
        $this->readBuffer = '';
    }

    public function __destruct()
    {
        $this->destroy();
    }

    public function disconnect(): void
    {
        $this->toDisconnect = true;
    }

    /**
     * @param positive-int $payloadSize Max payload size.
     */
    public static function init(
        \Socket $socket,
        int $payloadSize = 10485760,
    ): self {
        return new self($socket, $payloadSize);
    }

    public function process(): void
    {
        $this->onInit();

        do {
            $read = [$this->socket];
            $write = [$this->socket];
            $except = [$this->socket];
            if (\socket_select($read, $write, $except, 0, 0) === false) {
                throw new \RuntimeException('Socket select failed.');
            }

            /** @psalm-suppress RedundantCondition */
            if ($read !== []) {
                $this->readMessage();
            }

            /** @psalm-suppress RedundantCondition */
            if ($write !== [] && $this->writeQueue !== []) {
                $this->writeQueue();
            }

            /** @psalm-suppress RedundantCondition */
            if ($except !== [] || \socket_last_error($this->socket) !== 0) {
                throw new \RuntimeException('Socket exception.');
            }

            if ($this->toDisconnect && $this->writeQueue === []) {
                // Wait for the socket buffer to be flushed.
                // todo
                // (new Timer(0.005))->wait();
                throw new ClientDisconnected();
            }
            Fiber::suspend();
        } while (true);
    }

    protected function onInit(): void
    {
    }

    /**
     * @param callable(string): void $callable Non-static callable.
     * @psalm-assert callable(string): void $callable
     */
    public function setOnPayload(callable $callable): void
    {
        $this->onPayload = \Closure::bind($callable(...), $this);
    }

    /**
     * @param callable(): void $callable Non-static callable.
     * @psalm-assert callable(): void $callable
     */
    public function setOnClose(callable $callable): void
    {
        $this->onClose = \Closure::bind($callable(...), $this);
    }

    public function send(string $payload): void
    {
        if ($this->toDisconnect) {
            return;
        }

        $this->writeQueue[] = $payload;
    }

    /**
     * @param non-empty-string $payload
     */
    protected function processPayload(string $payload): void
    {
        ($this->onPayload)($payload);
    }

    private function writeQueue(): void
    {
        foreach ($this->writeQueue as $data) {
            \socket_write($this->socket, $data);
            // Logger::debug('Respond %d bytes', $x);
        }
        socket_set_nonblock($this->socket);

        $this->writeQueue = [];

        $this->toDisconnect and throw new ClientDisconnected();
    }

    private function readMessage(): void
    {
        $payload = $this->readBytes($this->payloadSize, true);

        if ($payload === '') {
            return;
        }
        $this->processPayload($payload);
    }

    /**
     * @param positive-int $length
     */
    private function readBytes(int $length, bool $canBeLess = false): string
    {
        while (($left = $length - \strlen($this->readBuffer)) > 0) {
            $data = '';
            $read = @\socket_recv($this->socket, $data, $left, 0);
            /** @psalm-suppress TypeDoesNotContainNull */
            if ($read === false || $data === null) {
                if ($this->readBuffer !== '') {
                    $result = $this->readBuffer;
                    $this->readBuffer = '';
                    return $result;
                }
                $errNo = \socket_last_error($this->socket);
                throw new \RuntimeException('Socket read failed [' . $errNo . ']: ' . \socket_strerror($errNo));
            }

            if ($canBeLess) {
                return $data;
            }

            if ($data === '') {
                Fiber::suspend();
                continue;
            }

            $this->readBuffer .= $data;
        }

        $result = \substr($this->readBuffer, 0, $length);
        $this->readBuffer = \substr($this->readBuffer, $length);

        return $result;
    }
}
