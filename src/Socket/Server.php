<?php

declare(strict_types=1);

namespace Buggregator\Trap\Socket;

use Buggregator\Trap\Cancellable;
use Buggregator\Trap\Destroyable;
use Buggregator\Trap\Logger;
use Buggregator\Trap\Processable;
use Buggregator\Trap\Socket\Exception\ClientDisconnected;
use Buggregator\Trap\Socket\Exception\ServerStopped;

/**
 * @internal
 */
final class Server implements Processable, Cancellable, Destroyable
{
    private \Socket $socket;

    /** @var array<int, Client> */
    private array $clients = [];

    /** @var array<int, \Fiber> */
    private array $fibers = [];

    private bool $cancelled = false;

    /** Timestamp with microseconds when last socket_accept() was called */
    private float $lastAccept = 0;

    /**
     * @param null|\Closure(Client, int $id): void $clientInflector
     * @param positive-int $payloadSize Max payload size.
     * @param float $acceptPeriod Time to wait between socket_accept() calls in seconds.
     */
    private function __construct(
        int $port,
        private readonly int $payloadSize,
        private readonly float $acceptPeriod,
        private readonly ?\Closure $clientInflector,
        private readonly Logger $logger,
    ) {
        $this->socket = @\socket_create_listen($port) ?: throw new \RuntimeException('Socket create failed.');

        /** @link https://github.com/buggregator/trap/pull/14 */
        // \socket_set_option($this->socket, \SOL_SOCKET, \SO_LINGER, ['l_linger' => 0, 'l_onoff' => 1]);

        \socket_set_nonblock($this->socket);

        $logger->status('App', 'Server started on 127.0.0.1:%s', $port);
    }

    /**
     * @param int<1, 65535> $port
     * @param positive-int $payloadSize Max payload size.
     * @param float $acceptPeriod Time to wait between socket_accept() calls in seconds.
     * @param null|\Closure(Client, int $id): void $clientInflector
     */
    public static function init(
        int $port = 9912,
        int $payloadSize = 10485760,
        float $acceptPeriod = .001,
        ?\Closure $clientInflector = null,
        Logger $logger = new Logger(),
    ): self {
        return new self($port, $payloadSize, $acceptPeriod, $clientInflector, $logger);
    }

    public function destroy(): void
    {
        /** @psalm-suppress all */
        foreach ($this->clients ?? [] as $client) {
            $client->destroy();
        }

        try {
            /** @psalm-suppress all */
            if (isset($this->socket)) {
                \socket_close($this->socket);
            }
        } catch (\Throwable) {
            // do nothing
        }
        unset($this->socket, $this->clients, $this->fibers);
    }

    public function process(): void
    {
        // /** @psalm-suppress PossiblyInvalidArgument */
        while (match(true) {
            $this->cancelled,
            \microtime(true) - $this->lastAccept <= $this->acceptPeriod => false,
            default => false !== ($socket = \socket_accept($this->socket)),
        }) {
            $this->lastAccept = \microtime(true);
            $client = null;
            try {
                /** @var \Socket $socket */
                $client = Client::init($socket, $this->payloadSize, $this->acceptPeriod);
                $key = (int) \array_key_last($this->clients) + 1;
                $this->clients[$key] = $client;
                $this->clientInflector !== null and ($this->clientInflector)($client, $key);
                $this->fibers[$key] = new \Fiber($client->process(...));
                /**
                 * The {@see self::$cancelled} may be changed because of fibers
                 * @psalm-suppress all
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
}
