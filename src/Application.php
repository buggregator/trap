<?php

declare(strict_types=1);

namespace Buggregator\Trap;

use Buggregator\Trap\Config\SocketServer;
use Buggregator\Trap\Handler\Http\Handler\Websocket;
use Buggregator\Trap\Handler\Http\Middleware;
use Buggregator\Trap\Proto\Buffer;
use Buggregator\Trap\Socket\Client;
use Buggregator\Trap\Socket\Server;
use Buggregator\Trap\Socket\SocketStream;
use Buggregator\Trap\Support\Timer;
use Buggregator\Trap\Traffic\Inspector;
use Fiber;

/**
 * @internal
 */
final class Application implements Processable
{
    /** @var Processable[] */
    private array $processors = [];

    /** @var Server[] */
    private array $servers = [];

    /** @var Fiber[] Any tasks in fibers */
    private array $fibers = [];

    private readonly Buffer $buffer;
    private Inspector $inspector;

    /**
     * @param SocketServer[] $map
     */
    public function __construct(
        array $map = [],
    ) {
        $this->buffer = new Buffer(bufferSize: 10485760, timer: 0.1);

        $this->inspector = new Inspector(
            $this->buffer,
            new Traffic\Dispatcher\VarDumper(),
            new Traffic\Dispatcher\Http([
                new Middleware\Resources(),
                new Middleware\DebugPage(),
                new Middleware\RayRequestDump(),
            ], [new Websocket()]),
            new Traffic\Dispatcher\Smtp(),
            new Traffic\Dispatcher\Monolog(),
        );
        $this->processors[] = $this->inspector;

        foreach ($map as $config) {
            $this->fibers[] = new Fiber(function () use ($config) {
                do {
                    try {
                        $this->processors[] = $this->servers[$config->port] = $this->createServer($config);
                        return;
                    } catch (\Throwable $e) {
                        Logger::error("Can't create TCP socket on port $config->port.");
                        (new Timer(1.0))->wait();
                    }
                } while (true);
            });
        }
    }

    /**
     * @param Sender[] $senders
     * @param positive-int $sleep Sleep time in microseconds
     */
    public function run(array $senders = [], int $sleep = 50): void
    {
        foreach ($senders as $sender) {
            \assert($sender instanceof Sender);
            if ($sender instanceof Processable) {
                $this->processors[] = $sender;
            }
        }

        while (true) {
            $this->process($senders);
            \usleep($sleep);
        }
    }

    /**
     * @param Sender[] $senders
     */
    public function process(array $senders = []): void
    {
        foreach ($this->processors as $server) {
            $server->process();
        }

        // Process buffer
        if ($this->buffer->isReady()) {
            $this->sendBuffer($senders);
        }

        foreach ($this->fibers as $key => $fiber) {
            try {
                $fiber->isStarted() ? $fiber->resume() : $fiber->start();

                if ($fiber->isTerminated()) {
                    unset($this->fibers[$key]);
                }
            } catch (\Throwable $e) {
                Logger::exception($e);
                unset($this->fibers[$key]);
            }
        }
    }

    /**
     * @param Sender[] $senders
     */
    private function sendBuffer(array $senders = []): void
    {
        $data = $this->buffer->getAndClean();

        foreach ($senders as $sender) {
            $this->fibers[] = new Fiber(
                static fn() => $sender->send($data)
            );
        }
    }

    /**
     * @param int<1, 65535> $port
     */
    private function createServer(SocketServer $config): Server
    {
        $inspector = $this->inspector;
        $clientInflector = function (Client $client, int $id) use ($inspector): Client {
            Logger::debug('New client connected %d', $id);
            $inspector->addStream(SocketStream::create($client, $id));
            return $client;
        };

        return Server::init($config->port, payloadSize: 524_288, clientInflector: $clientInflector);
    }
}
