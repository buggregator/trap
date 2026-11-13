<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Socket;

use Buggregator\Trap\Server\Cancellable;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Server\Processable;
use Buggregator\Trap\Server\Socket\Exception\ClientDisconnected;
use Buggregator\Trap\Server\Socket\Exception\ServerStopped;
use Buggregator\Trap\Support\Activity;
use Internal\Destroy\Destroyable;

/**
 * @internal
 */
final class Server implements Processable, Cancellable, Destroyable
{
    /**
     * With the default buffer a client on Windows sends a large body by a few hundred KB per ~15 ms
     * timer tick: 16 MB takes about 500 ms instead of 40 ms.
     */
    private const WINDOWS_RECEIVE_BUFFER = 1024 * 1024;

    /** Connections to accept in one {@see self::process()}: the rest wait for the next one */
    private const ACCEPT_LIMIT = 32;

    /** Seconds to stop accepting after socket_accept() runs out of resources */
    private const ACCEPT_RETRY_DELAY = 1.0;

    /** Errors of socket_accept() that the next calls would get too */
    private const ACCEPT_EXHAUSTED = ['SOCKET_EMFILE', 'SOCKET_ENFILE', 'SOCKET_ENOBUFS', 'SOCKET_ENOMEM'];

    private \Socket $socket;

    /** @var array<int, Client> */
    private array $clients = [];

    /** @var array<int, \Fiber> */
    private array $fibers = [];

    private bool $cancelled = false;

    /** Timestamp with microseconds when last socket_accept() was called */
    private float $lastAccept = 0;

    /** The main loop has seen a connection waiting */
    private bool $acceptReady = false;

    /** Timestamp with microseconds before which no connection is accepted */
    private float $acceptPausedUntil = 0;

    /**
     * @param non-empty-string $protocol
     * @param non-empty-string $address
     * @param int<1, 65535> $port
     * @param null|\Closure(Client, int $id): mixed $clientInflector
     * @param positive-int $payloadSize Max payload size.
     * @param float $acceptPeriod Time to wait between socket_accept() calls in seconds.
     */
    private function __construct(
        string $protocol,
        string $address,
        int $port,
        private readonly int $payloadSize,
        private readonly float $acceptPeriod,
        private readonly ?\Closure $clientInflector,
        private readonly Logger $logger,
    ) {
        $protocolNumber = \getprotobyname($protocol);
        $protocolNumber === false and throw new \RuntimeException(\sprintf('Unknown protocol `%s`.', $protocol));
        $this->socket = \socket_create(\AF_INET, \SOCK_STREAM, $protocolNumber)
            ?: throw new \RuntimeException('Socket create failed.');
        @\socket_set_nonblock($this->socket)
            ?: throw new \RuntimeException('Socket set nonblock failed.');
        @\socket_bind($this->socket, $address, $port)
            ?: throw new \RuntimeException('Socket bind failed.');

        /** @link https://github.com/buggregator/trap/pull/14 */
        // \socket_set_option($this->socket, \SOL_SOCKET, \SO_LINGER, ['l_linger' => 0, 'l_onoff' => 1]);

        // Accepted sockets inherit it. Linux tunes the buffer itself unless it is set.
        \PHP_OS_FAMILY === 'Windows' and @\socket_set_option(
            $this->socket,
            \SOL_SOCKET,
            \SO_RCVBUF,
            self::WINDOWS_RECEIVE_BUFFER,
        );

        @\socket_listen($this->socket, \SOMAXCONN)
            ?: throw new \RuntimeException('Socket listen failed.');

        $logger->status('App', 'Server started on %s:%s', $address, $port);
    }

    /**
     * @param non-empty-string $protocol
     * @param non-empty-string $address
     * @param int<1, 65535> $port
     * @param positive-int $payloadSize Max payload size.
     * @param float $acceptPeriod Time to wait between socket_accept() calls in seconds.
     * @param null|\Closure(Client, int $id): mixed $clientInflector
     */
    public static function init(
        string $protocol,
        string $address,
        int $port,
        int $payloadSize = 10485760,
        float $acceptPeriod = .001,
        ?\Closure $clientInflector = null,
        Logger $logger = new Logger(),
    ): self {
        return new self($protocol, $address, $port, $payloadSize, $acceptPeriod, $clientInflector, $logger);
    }

    #[\Override]
    public function destroy(): void
    {
        /** @psalm-suppress RedundantCondition, TypeDoesNotContainType The property is unset by a previous destroy() call */
        foreach ($this->clients ?? [] as $client) {
            $client->destroy();
        }

        try {
            /** @psalm-suppress RedundantPropertyInitializationCheck The property is unset by a previous destroy() call */
            if (isset($this->socket)) {
                \socket_close($this->socket);
            }
        } catch (\Throwable) {
            // do nothing
        }
        unset($this->socket, $this->clients, $this->fibers);
    }

    #[\Override]
    public function process(): void
    {
        if ($this->isAccepting()
            && ($this->acceptReady || \microtime(true) - $this->lastAccept > $this->acceptPeriod)
        ) {
            $this->acceptClients();
        }

        foreach ($this->fibers as $key => $fiber) {
            try {
                $fiber->isStarted() ? $fiber->resume() : $fiber->start();

                if ($fiber->isTerminated()) {
                    throw new \RuntimeException("Client $key terminated.");
                }
            } catch (\Throwable $e) {
                if ($e instanceof ClientDisconnected) {
                    $this->logger->debug('Client %s disconnected', $key);
                } else {
                    $this->logger->exception($e, "Client $key fiber.");
                }

                $this->clients[$key]->destroy();
                unset($this->clients[$key], $this->fibers[$key]);
            }
        }

        if ($this->cancelled && $this->fibers === []) {
            throw new ServerStopped();
        }
    }

    /**
     * @return array{list<\Socket>, list<\Socket>} Sockets to wait for reading and for writing: only those
     *         the server takes on the next {@see self::process()}.
     */
    public function getSockets(): array
    {
        $read = $write = [];
        /** @psalm-suppress RedundantPropertyInitializationCheck The property is unset by destroy() */
        if (!isset($this->socket)) {
            return [$read, $write];
        }

        $this->isAccepting() and $read[] = $this->socket;
        foreach ($this->clients as $client) {
            $socket = $client->readableSocket();
            $socket === null or $read[] = $socket;
            $socket = $client->writableSocket();
            $socket === null or $write[] = $socket;
        }

        return [$read, $write];
    }

    /**
     * Lets the server take the ready sockets on the next {@see self::process()} without waiting for its periods.
     *
     * @param list<\Socket> $sockets
     */
    public function wake(array $sockets): void
    {
        /** @psalm-suppress RedundantPropertyInitializationCheck The property is unset by destroy() */
        if (!isset($this->socket)) {
            return;
        }

        $ready = [];
        foreach ($sockets as $socket) {
            $ready[\spl_object_id($socket)] = true;
        }

        isset($ready[\spl_object_id($this->socket)]) and $this->acceptReady = true;
        foreach ($this->clients as $client) {
            isset($ready[\spl_object_id($client->getSocket())]) and $client->wake();
        }
    }

    #[\Override]
    public function cancel(): void
    {
        $this->cancelled = true;
        foreach ($this->clients as $client) {
            $client->disconnect();
        }
    }

    public function __destruct()
    {
        $this->destroy();
    }

    private static function isExhausted(int $errNo): bool
    {
        foreach (self::ACCEPT_EXHAUSTED as $name) {
            if (\defined($name) && \constant($name) === $errNo) {
                return true;
            }
        }

        return false;
    }

    private function isAccepting(): bool
    {
        return !$this->cancelled && \microtime(true) >= $this->acceptPausedUntil;
    }

    private function acceptClients(): void
    {
        $this->acceptReady = false;
        for ($i = 0; $i < self::ACCEPT_LIMIT; ++$i) {
            $socket = @\socket_accept($this->socket);
            if ($socket === false) {
                break;
            }

            Activity::report();
            $this->addClient($socket);
            if ($this->cancelled) {
                return;
            }
        }

        $this->lastAccept = \microtime(true);
        if ($i === self::ACCEPT_LIMIT) {
            $this->acceptReady = true;
            return;
        }

        $errNo = \socket_last_error($this->socket);
        \socket_clear_error($this->socket);
        if ($errNo === 0 || $errNo === \SOCKET_EAGAIN || $errNo === \SOCKET_EWOULDBLOCK) {
            return;
        }

        if (self::isExhausted($errNo)) {
            // The connection stays in the backlog until descriptors or memory are freed
            $this->acceptPausedUntil = $this->lastAccept + self::ACCEPT_RETRY_DELAY;
            $this->logger->error('Accepting a connection failed [%d]: %s', $errNo, \socket_strerror($errNo));
            return;
        }

        // The connection was lost before it was accepted: the others may be waiting
        $this->acceptReady = true;
        $this->logger->debug('Accepting a connection failed [%d]: %s', $errNo, \socket_strerror($errNo));
    }

    private function addClient(\Socket $socket): void
    {
        $client = null;
        try {
            $client = Client::init($socket, $this->payloadSize, $this->acceptPeriod);
            $key = (int) \array_key_last($this->clients) + 1;
            $this->clients[$key] = $client;
            $this->clientInflector !== null and ($this->clientInflector)($client, $key);
            $this->fibers[$key] = new \Fiber($client->process(...));
            /**
             * The {@see self::$cancelled} may be changed because of fibers
             */
            $this->cancelled and $client->disconnect();
        } catch (\Throwable) {
            $client?->destroy();
            unset($client);
            if (isset($key)) {
                unset($this->clients[$key], $this->fibers[$key]);
            }
        }
    }
}
