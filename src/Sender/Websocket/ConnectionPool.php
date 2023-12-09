<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Websocket;

use Buggregator\Trap\Processable;
use Buggregator\Trap\Traffic\StreamClient;
use Buggregator\Trap\Traffic\Websocket\Frame;
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

    public function addStream(StreamClient $frame): void
    {
        $this->streams[] = $frame;
    }

    public function process(): void
    {
        foreach ($this->streams as $key => $stream) {
            if ($stream->isDisconnected()) {
                \error_log('Websocket disconnected');
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
}
