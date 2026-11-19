<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Socket;

use Buggregator\Trap\Server\Socket\Exception\ClientDisconnected;
use Buggregator\Trap\Server\Socket\Exception\TlsHandshakeFailed;
use Buggregator\Trap\Server\Socket\Tls\Context;
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

    /** The type of a TLS record with a handshake message: a TLS connection opens with a ClientHello in one */
    private const TLS_HANDSHAKE_RECORD = "\x16";

    /** @var string[] */
    private array $writeQueue = [];

    /** @var resource|null The socket as a stream, once TLS is on */
    private mixed $stream = null;

    /** The first bytes are yet to be checked for a TLS handshake */
    private bool $detectTls;

    /** Seconds of {@see \microtime()} the TLS handshake ends by, null while none runs */
    private ?float $handshakeUntil = null;

    /** TLS holds decrypted data that the socket doesn't show as readable */
    private bool $tlsPending = false;

    /** The handshake starts once the write queue is empty */
    private bool $startTls = false;

    private bool $toDisconnect = false;

    /** The main loop has seen the socket ready */
    private bool $ready = false;

    /** The send buffer is full: the client doesn't read until it is written */
    private bool $writeBlocked = false;

    /** Seconds of {@see \microtime()} at the last check of the socket */
    private float $checkedAt;

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
        private readonly ?Context $tls,
    ) {
        $this->detectTls = $tls !== null;
        $this->selectTimer = new Timer($selectPeriod);
        $this->checkedAt = \microtime(true);
        \socket_set_nonblock($this->socket);
        $this->setOnPayload(static fn(string $payload) => null);
        $this->setOnClose(static fn() => null);
    }

    /**
     * @param positive-int $payloadSize Max payload size.
     * @param float $selectPeriod Seconds between socket_select() calls the main loop doesn't wake for.
     * @param Context|null $tls Accepts a TLS connection next to a plain one when set.
     */
    public static function init(
        \Socket $socket,
        int $payloadSize = 10485760,
        float $selectPeriod = self::POLL_PERIOD,
        ?Context $tls = null,
    ): self {
        return new self(
            socket: $socket,
            payloadSize: $payloadSize,
            selectPeriod: $selectPeriod,
            tls: $tls,
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

    /**
     * @return float Seconds of {@see \microtime()} at the last check of the socket: what the peer had sent
     *         by then is read, at least its first part.
     */
    public function checkedAt(): float
    {
        return $this->checkedAt;
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
        return $this->writeQueue === [] || $this->handshakeUntil !== null ? null : $this->socket;
    }

    public function process(): void
    {
        $this->onInit();

        do {
            $progress = $this->ready = false;
            $checkedAt = \microtime(true);
            $read = [$this->socket];
            $write = [$this->socket];
            $except = [$this->socket];
            if (\socket_select($read, $write, $except, 0, 0) === false) {
                throw new \RuntimeException('Socket select failed.');
            }

            /** @psalm-suppress RedundantCondition */
            if ($read !== [] || $this->tlsPending) {
                $progress = $this->readMessage();
            }
            $this->checkedAt = $checkedAt;

            $this->handshakeUntil !== null && $checkedAt > $this->handshakeUntil
                and throw new TlsHandshakeFailed('Timed out.');

            /** @psalm-suppress RedundantCondition */
            if ($write !== [] && $this->writeQueue !== [] && $this->handshakeUntil === null) {
                $this->writeQueue();
                $progress = true;
            }

            // The peer starts the handshake once it has read the answer to its request for TLS
            /** @psalm-suppress RedundantCondition */
            if ($this->startTls && $this->writeQueue === []) {
                $this->startTls = false;
                $this->startHandshake();
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
            } while (
                !$this->ready
                && $this->writeQueue === []
                && !$this->toDisconnect
                && !$this->startTls
                && !$this->selectTimer->isReady()
            );
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

    /**
     * @return bool Whether {@see self::startTls()} can switch the connection to TLS.
     */
    public function canStartTls(): bool
    {
        return $this->tls?->trusted === true && $this->stream === null && !$this->startTls && !$this->toDisconnect;
    }

    /**
     * Switches the connection to TLS once the queued data is written.
     *
     * @throws \LogicException When {@see self::canStartTls()} is false.
     */
    public function startTls(): void
    {
        $this->canStartTls() or throw new \LogicException('TLS is unavailable on the connection.');
        $this->startTls = true;
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
            $result = $this->write($data);
            if ($result === null) {
                // The send buffer is full: retry once the main loop sees the socket writable
                $this->writeBlocked = true;
                \Fiber::suspend();
                continue;
            }

            // Unrecoverable error, stop writing
            if ($result === false) {
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
     * @return int|false|null Bytes written, null when the send buffer is full, false on an error.
     */
    private function write(string $data): int|false|null
    {
        if ($this->stream !== null) {
            // TLS must be given the same data again after a write it couldn't make
            \error_clear_last();
            $result = @\fwrite($this->stream, $data);
            if ($result !== 0) {
                return $result;
            }

            // A full send buffer writes nothing silently. A reset connection writes nothing too, again and again,
            // but warns, and the socket may no longer show it.
            return \error_get_last() !== null || $this->peerClosed() ? false : null;
        }

        $result = @\socket_write($this->socket, $data);
        if ($result !== false) {
            return $result;
        }

        $errNo = \socket_last_error($this->socket);
        if ($errNo === \SOCKET_EAGAIN || $errNo === \SOCKET_EWOULDBLOCK) {
            \socket_clear_error($this->socket);
            return null;
        }

        return false;
    }

    /**
     * Reads what the socket has, up to the payload size.
     *
     * @return bool Whether anything was read.
     */
    private function readMessage(): bool
    {
        if ($this->detectTls) {
            $tls = $this->opensTls();
            if ($tls === null) {
                return false;
            }

            $this->detectTls = false;
            $tls and $this->startHandshake();
        }

        if ($this->handshakeUntil !== null && !$this->handshake()) {
            return false;
        }

        $payload = $this->stream === null ? $this->readSocket() : $this->readStream();
        if ($payload === '') {
            return false;
        }

        $this->processPayload($payload);
        return true;
    }

    private function readSocket(): string
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

                // An orderly close by the peer reads 0 bytes and sets no error
                $read === 0 && $errNo === 0 and throw new ClientDisconnected();

                throw new \RuntimeException('Socket read failed [' . $errNo . ']: ' . \socket_strerror($errNo));
            }

            $payload .= $data;
        }

        return $payload;
    }

    /**
     * Reads until TLS has no more data: what it has decrypted doesn't make the socket readable.
     */
    private function readStream(): string
    {
        \assert($this->stream !== null);
        $payload = '';
        while (($left = $this->payloadSize - \strlen($payload)) > 0) {
            $data = @\fread($this->stream, $left);
            if ($data === false || $data === '') {
                // The read data goes first: the next iteration fails again
                if ($payload !== '') {
                    break;
                }

                $this->peerClosed() and throw new ClientDisconnected();
                $data === false and throw new \RuntimeException('TLS read failed.');
                break;
            }

            $payload .= $data;
        }

        $this->tlsPending = \strlen($payload) >= $this->payloadSize;
        return $payload;
    }

    /**
     * @return bool|null Whether the peer has started a TLS handshake, null while it has sent nothing.
     */
    private function opensTls(): ?bool
    {
        $data = '';
        $read = @\socket_recv($this->socket, $data, 1, \MSG_PEEK);
        if ($read === false) {
            $errNo = \socket_last_error($this->socket);
            if ($errNo === \SOCKET_EAGAIN || $errNo === \SOCKET_EWOULDBLOCK) {
                \socket_clear_error($this->socket);
                return null;
            }
        }

        // A closed or failed connection is left to the plain reading to report
        return $data === self::TLS_HANDSHAKE_RECORD;
    }

    /**
     * Checks the socket, not the TLS stream: {@see \feof()} of the stream peeks through TLS, which takes data
     * just come in into its buffer, where the socket no longer shows it readable and it is never read.
     */
    private function peerClosed(): bool
    {
        $data = '';
        $read = @\socket_recv($this->socket, $data, 1, \MSG_PEEK);
        if ($read !== false) {
            return $read === 0;
        }

        $errNo = \socket_last_error($this->socket);
        \socket_clear_error($this->socket);
        return $errNo !== \SOCKET_EAGAIN && $errNo !== \SOCKET_EWOULDBLOCK;
    }

    private function startHandshake(): void
    {
        \assert($this->tls !== null);
        $stream = \socket_export_stream($this->socket);
        $stream === false and throw new \RuntimeException('Failed to export the socket to a stream.');
        \stream_set_blocking($stream, false);
        foreach ($this->tls->options as $name => $value) {
            \stream_context_set_option($stream, 'ssl', $name, $value);
        }

        $this->stream = $stream;
        $this->handshakeUntil = \microtime(true) + $this->tls->handshakeTimeout;
    }

    /**
     * Takes the handshake as far as the data read allows.
     *
     * @return bool Whether the handshake has completed.
     *
     * @throws TlsHandshakeFailed
     */
    private function handshake(): bool
    {
        \assert($this->stream !== null);
        \error_clear_last();
        // TLS 1.3 sends session tickets after the handshake: a client that writes and closes without reading
        // them resets the connection, and its data is lost before it is read
        $result = @\stream_socket_enable_crypto($this->stream, true, \STREAM_CRYPTO_METHOD_TLSv1_2_SERVER);
        if ($result === 0) {
            // The handshake keeps waiting for data from a peer that has closed the connection
            $this->peerClosed() and throw new ClientDisconnected();
            return false;
        }

        if ($result !== true) {
            $error = \error_get_last()['message'] ?? 'Unknown error.';
            while (\openssl_error_string() !== false);
            throw new TlsHandshakeFailed($error);
        }

        $this->handshakeUntil = null;
        return true;
    }
}
