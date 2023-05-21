<?php

declare(strict_types=1);

namespace Buggregator\Client;

use Buggregator\Client\Config\SocketServer;
use Buggregator\Client\Proto\Buffer;
use Buggregator\Client\Socket\Client;
use Buggregator\Client\Socket\Server;
use Buggregator\Client\Socket\StreamClient;
use Buggregator\Client\Support\Timer;
use Buggregator\Client\Traffic\Http\DebugPage;
use Buggregator\Client\Traffic\Http\HandlerPipeline;
use Buggregator\Client\Traffic\Http\RayRequestDump;
use Buggregator\Client\Traffic\Inspector;
use Fiber;

/**
 * @internal
 */
final class Application implements Processable
{
    public const VERSION = '0.1.2';

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
     * @param Sender[] $senders
     */
    public function __construct(
        array $map = [],
        private readonly array $senders = [],
    ) {
        $this->buffer = new Buffer(bufferSize: 10485760, timer: 0.1);

        $httpHandler = new HandlerPipeline();
        $httpHandler->register(new DebugPage());
        $httpHandler->register(new RayRequestDump());

        $this->inspector = new Inspector(
            $this->buffer,
            new Traffic\Dispatcher\VarDumper(),
            new Traffic\Dispatcher\Http($httpHandler),
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

        foreach ($this->senders as $sender) {
            \assert($sender instanceof Sender);
            if ($sender instanceof Processable) {
                $this->processors[] = $sender;
            }
        }
    }

    /**
     * @param int $sleep Sleep time in microseconds
     */
    public function run(int $sleep = 50): void
    {
        while (true) {
            $this->process();
            \usleep($sleep);
        }
    }

    public function process(): void
    {
        foreach ($this->processors as $server) {
            $server->process();
        }

        // Process buffer
        if ($this->buffer->isReady()) {
            $this->sendBuffer();
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

    private function sendBuffer(): void
    {
        $this->fibers[] = new Fiber(
            function (): void {
                $data = $this->buffer->getAndClean();

                foreach ($this->senders as $sender) {
                    // TODO: fix error handling for socket sender, then remote server does not respond
                    $sender->send($data);
                }
            }
        );
    }

    /**
     * @param int<1, 65535> $port
     */
    private function createServer(SocketServer $config): Server
    {
        $inspector = $this->inspector;
        $clientInflector = function (Client $client, int $id) use ($inspector): Client {
            Logger::debug('New client connected %d', $id);
            $inspector->addStream(StreamClient::create($client, $id));
            return $client;
        };

        return Server::init($config->port, payloadSize: 524_288, clientInflector: $clientInflector);
    }
}
