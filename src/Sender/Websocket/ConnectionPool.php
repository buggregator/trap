<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Websocket;

use Buggregator\Trap\Logger;
use Buggregator\Trap\Processable;
use Buggregator\Trap\Traffic\StreamClient;
use Buggregator\Trap\Traffic\Websocket\Frame;
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

    public function addStream(StreamClient $frame): void
    {
        $key = (int)\array_key_last($this->streams) + 1;
        $this->streams[$key] = $frame;
        $this->fibers[$key] = new Fiber($this->processSocket(...));
    }

    public function process(): void
    {
        foreach ($this->fibers as $key => $fiber) {
            try {
                $fiber->isStarted() ? $fiber->resume() : $fiber->start($this->streams[$key]);

                if ($fiber->isTerminated()) {
                    unset($this->fibers[$key]);
                    unset($this->streams[$key]);
                }
            } catch (\Throwable $e) {
                $this->logger->exception($e);
                unset($this->fibers[$key]);
                unset($this->streams[$key]);
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
        while (true) {
            if ($stream->isDisconnected()) {
                return;
            }

            foreach ($stream as $chunk) {
                $frame = Frame::read($chunk);
                $response = $this->rpc->handleMessage($frame->content);

                if (null !== $response) {
                    $stream->sendData(Frame::text($response)->__toString());
                }
            }

            $stream->waitData();
        }
    }
}
