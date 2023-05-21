<?php

declare(strict_types=1);

namespace Buggregator\Client;

use Buggregator\Client\Proto\Buffer;
use Buggregator\Client\Socket\Client;
use Buggregator\Client\Socket\Server;
use Buggregator\Client\Socket\StreamClient;
use Buggregator\Client\Support\Timer;
use Buggregator\Client\Traffic\Http\RayRequestDump;
use Buggregator\Client\Traffic\Http\HandlerPipeline;
use Buggregator\Client\Traffic\Inspector;
use Fiber;

/**
 * @internal
 */
final class Bootstrap implements Processable
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
     * @param array<positive-int, mixed> $map Port mapping
     * @param Sender[] $senders
     */
    public function __construct(
        object $options,
        array $map = [
            9912 => [],
        ],
        private readonly array $senders = [],
    ) {
        $this->buffer = new Buffer(bufferSize: 10485760, timer: 0.1);

        $httpHandler = new HandlerPipeline();
        $httpHandler->register(new RayRequestDump());

        $this->inspector = new Inspector(
            $this->buffer,
            new Traffic\Dispatcher\VarDumper(),
            new Traffic\Dispatcher\Http($httpHandler),
            new Traffic\Dispatcher\Smtp(),
            new Traffic\Dispatcher\Monolog(),
        );
        $this->processors[] = $this->inspector;

        foreach ($map as $port => $_) {
            $this->fibers[] = new Fiber(function () use ($port) {
                do {
                    try {
                        $this->processors[] = $this->servers[$port] = $this->createServer($port);
                        return;
                    } catch (\Throwable $e) {
                        Logger::error("Can't create TCP socket on port $port.");
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
     * @param positive-int $port
     */
    private function createServer(int $port): Server
    {
        $inspector = $this->inspector;
        $clientInflector = function (Client $client, int $id) use ($inspector): Client {
            Logger::debug('New client connected %d', $id);
            $inspector->addStream(StreamClient::create($client, $id));
            return $client;
        };

        return Server::init($port, payloadSize: 524_288, clientInflector: $clientInflector);
    }
}
