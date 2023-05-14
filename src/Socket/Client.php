<?php

declare(strict_types=1);

namespace Buggregator\Client\Socket;

use Fiber;

/**
 * Client state on the server side.
 */
class Client
{
    /** @var string[] */
    private array $writeQueue = [];

    /** @var string */
    private string $readBuffer = '';

    private \Closure $onPayload;
    private \Closure $onClose;

    private function __construct(
        private readonly \Socket $socket,
        private readonly int $payloadSize,
        private readonly bool $binary,
    ) {
        \socket_set_nonblock($this->socket);
        $this->setOnPayload(fn(string $payload) => null);
        $this->setOnClose(fn() => null);
    }

    public function __destruct()
    {
        try {
            \socket_close($this->socket);
        } catch (\Throwable) {
        } finally {
            ($this->onClose)();
        }
    }

    /**
     * @param bool $binary If {@see false}, \r \n \0 boundaries will be respected.
     * @param positive-int $payloadSize Max payload size.
     */
    public static function init(
        \Socket $socket,
        int $payloadSize = 10485760,
        bool $binary = true,
    ): self {
        return new self($socket, $payloadSize, $binary);
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

            if ($read !== []) {
                $this->readMessage();
            }

            if ($write !== [] && $this->writeQueue !== []) {
                $this->writeQueue();
            }

            Fiber::suspend();
        } while (true);
    }

    public function isBinary(): bool
    {
        return $this->binary;
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
        }
        socket_set_nonblock($this->socket);

        $this->writeQueue = [];
    }

    private function readMessage(): void
    {
        $payload = $this->readBytes($this->payloadSize, true, $this->binary);

        $this->processPayload($payload);
    }

    /**
     * @param positive-int $length
     * @param bool $binary If {@see false} then \r, \n and \0 will be bounders.
     *
     * @return non-empty-string
     */
    private function readBytes(int $length, bool $canBeLess = false, bool $binary = true): string
    {
        while (($left = $length - \strlen($this->readBuffer)) > 0) {
            $data = @\socket_read($this->socket, $left, $binary ? \PHP_BINARY_READ : \PHP_NORMAL_READ);
            if ($data === false) {
                $errNo = \socket_last_error($this->socket);
                throw new \RuntimeException('Socket read failed [' . $errNo . ']: ' . \socket_strerror($errNo));
            }

            if ($data === '') {
                Fiber::suspend();
                continue;
            }

            if ($canBeLess) {
                return $data;
            }

            $this->readBuffer .= $data;
        }

        $result = \substr($this->readBuffer, 0, $length);
        $this->readBuffer = \substr($this->readBuffer, $length);

        return $result;
    }
}
