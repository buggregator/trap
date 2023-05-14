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

    public function __construct(
        object $options,
        array $map = [
            'vardump' => 9912,
        ],
        Sender $sender = null,
    ) {
        $this->buffer = new Buffer(bufferSize: 10485760, timer: 0.1);
        $this->inspector = new Inspector(
            $this->buffer,
            new Traffic\Dispatcher\VarDumper(),
        );

        foreach ($map as $type => $port) {
            $protoType = ProtoType::tryFrom($type);
            $this->servers[$type] = $this->createServer($protoType, $port);
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

    private function createServer(?ProtoType $type, int $port): Server
    {
        $inspector = $this->inspector;
        $clientInflector = $type === null ? null : function (Client $client, int $id) use ($inspector): Client {
            $inspector->addStream(StreamClient::create($client, $id));
            return $client;
        };

        return match($type) {
            ProtoType::VarDumper => Server::init($port, binary: false, clientInflector: $clientInflector),
            default => Server::init($port),
        };
    }
}
