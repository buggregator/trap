<?php

declare(strict_types=1);

namespace Buggregator\Trap\Socket;

use Buggregator\Trap\Cancellable;
use Buggregator\Trap\Destroyable;
use Buggregator\Trap\Logger;
use Buggregator\Trap\Processable;
use Buggregator\Trap\Socket\Exception\ClientDisconnected;
use Buggregator\Trap\Socket\Exception\ServerStopped;
use Closure;
use Fiber;
use RuntimeException;
use Socket;

/**
 * @internal
 */
final class Server implements Processable, Cancellable, Destroyable
{
    /** @var false|resource|Socket */
    private $socket;

    /** @var array<int, Client> */
    private array $clients = [];

    /** @var array<int, Fiber> */
    private array $fibers = [];

    private bool $cancelled = false;

    /**
     * @param null|Closure(Client, int $id): void $clientInflector
     * @param positive-int $payloadSize Max payload size.
     */
    private function __construct(
        int $port,
        private readonly int $payloadSize,
        private readonly ?Closure $clientInflector,
        private readonly Logger $logger,
    ) {
        $this->socket = @\socket_create_listen($port);
        /** @link https://github.com/buggregator/trap/pull/14 */
        \socket_set_option($this->socket, \SOL_SOCKET, \SO_LINGER, ['l_linger' => 0, 'l_onoff' => 1]);

        if ($this->socket === false) {
            throw new \RuntimeException('Socket create failed.');
        }
        \socket_set_nonblock($this->socket);

        $logger->status('Application', 'Server started on 127.0.0.1:%s', $port);
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

    public function __destruct()
    {
        $this->destroy();
    }

    /**
     * @param int<1, 65535> $port
     * @param positive-int $payloadSize Max payload size.
     * @param null|\Closure(Client, int $id): void $clientInflector
     */
    public static function init(
        int $port = 9912,
        int $payloadSize = 10485760,
        ?Closure $clientInflector = null,
        Logger $logger = new Logger(),
    ): self {
        return new self($port, $payloadSize, $clientInflector, $logger);
    }

    public function process(): void
    {
        while (!$this->cancelled and false !== ($socket = \socket_accept($this->socket))) {
            $client = null;
            try {
                $client = Client::init($socket, $this->payloadSize);
                $key = (int)\array_key_last($this->clients) + 1;
                $this->clients[$key] = $client;
                $this->clientInflector !== null and ($this->clientInflector)($client, $key);
                $this->fibers[$key] = new Fiber($client->process(...));
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
                    throw new RuntimeException("Client $key terminated.");
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
}
