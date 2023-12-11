<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Websocket;

use Buggregator\Trap\Logger;
use Buggregator\Trap\Processable;
use Buggregator\Trap\Support\Json;
use Buggregator\Trap\Support\Timer;
use Buggregator\Trap\Traffic\StreamClient;
use Buggregator\Trap\Traffic\Websocket\Frame;
use Buggregator\Trap\Traffic\Websocket\Opcode;
use Buggregator\Trap\Traffic\Websocket\StreamReader;
use Fiber;
use IteratorAggregate;
use Traversable;

/**
 * @internal
 * @implements IteratorAggregate<StreamClient>
 */
final class ConnectionPool implements IteratorAggregate, Processable
{
    /** @var StreamClient[] */
    private array $streams = [];
    /** @var Fiber[] */
    private array $fibers = [];

    public function __construct(
        private readonly Logger $logger,
        private RPC $rpc,
    ) {
    }

    public function addStream(StreamClient $stream): void
    {
        $key = (int)\array_key_last($this->streams) + 1;
        $this->streams[$key] = $stream;
        $this->fibers[] = new Fiber(function () use ($key, $stream) {
            try {
                $this->processSocket($stream);
            } finally {
                unset($this->streams[$key]);
            }
        });
    }

    public function process(): void
    {
        foreach ($this->fibers as $key => $fiber) {
            try {
                $fiber->isStarted() ? $fiber->resume() : $fiber->start();

                if ($fiber->isTerminated()) {
                    unset($this->fibers[$key]);
                }
            } catch (\Throwable $e) {
                $this->logger->exception($e);
                unset($this->fibers[$key]);
            }
        }
    }

    /**
     * @return Traversable<StreamClient>
     */
    public function getIterator(): Traversable
    {
        foreach ($this->streams as $stream) {
            yield $stream;
        }
    }

    public function send(Frame $frame): void
    {
        $data = (string)$frame;
        foreach ($this->streams as $stream) {
            $stream->sendData($data);
        }
    }

    private function processSocket(StreamClient $stream): void
    {
        $pingTimer = null;

        foreach (StreamReader::readFrames($stream->getIterator()) as $frame) {
            // Connection close
            if ($frame->opcode === Opcode::Close) {
                break;
            }

            // Ping-pong
            $frame->opcode === Opcode::Ping and $stream->sendData(Frame::pong($frame->content)->__toString());

            // RPC
            $response = $this->rpc->handleMessage($frame->content);

            // On connected ping using `{}` message
            if ($response instanceof RPC\Connected && $response->ping > 0){
                $pingTimer = new Timer($response->ping);
                $this->fibers[] = new Fiber(
                    function () use ($stream, $pingTimer): void {
                        while ($pingTimer->wait() && !$stream->isDisconnected()) {
                            $stream->sendData(Frame::text('{}')->__toString());
                            $pingTimer->reset();
                        }
                    }
                );
            }

            if (null !== $response) {
                $stream->sendData(Frame::text(Json::encode($response))->__toString());
                $pingTimer?->reset();
            }
        }
    }
}
