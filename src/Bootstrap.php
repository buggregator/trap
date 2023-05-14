<?php

declare(strict_types=1);

namespace Buggregator\Client;

use Buggregator\Client\Proto\Buffer;
use Buggregator\Client\Sender\FileSender;
use Buggregator\Client\Socket\Client;
use Buggregator\Client\Socket\Server;
use Buggregator\Client\Socket\StreamClient;
use Buggregator\Client\Traffic\Inspector;
use Fiber;
use RuntimeException;

class Bootstrap
{
    /** @var Server[] */
    private array $servers = [];
    /** @var Fiber[] Any tasks in fibers */
    private array $fibers = [];
    private readonly Buffer $buffer;
    private Sender $sender;
    private Inspector $inspector;

    /**
     * @param array<positive-int, mixed> $map Port mapping
     */
    public function __construct(
        object $options,
        array $map = [
            9912 => [],
        ],
        Sender $sender = null,
    ) {
        $this->buffer = new Buffer(bufferSize: 10485760, timer: 0.1);
        $this->inspector = new Inspector(
            $this->buffer,
            new Traffic\Dispatcher\VarDumper(),
            new Traffic\Dispatcher\Http(),
            new Traffic\Dispatcher\Smtp(),
        );

        foreach ($map as $port => $_) {
            $this->servers[$port] = $this->createServer($port);
        }
        $this->sender = $sender ?? new FileSender();

    }

    public function process(): void
    {
        foreach ($this->servers as $server) {
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
                    throw new RuntimeException('Client terminated.');
                }
            } catch (\Throwable) {
                unset($this->fibers[$key]);
            }
        }
        $this->inspector->process();
    }

    private function sendBuffer(): void
    {
        $this->fibers[] = new Fiber(fn() => $this->sender->send($this->buffer->getAndClean()));
    }

    private function createServer(int $port): Server
    {
        $inspector = $this->inspector;
        $clientInflector = function (Client $client, int $id) use ($inspector): Client {
            Logger::debug('New client connected %d', $id);
            $inspector->addStream(StreamClient::create($client, $id));
            return $client;
        };

        return Server::init($port, payloadSize: 40, clientInflector: $clientInflector);
    }
}
