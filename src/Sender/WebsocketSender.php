<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender;

use Buggregator\Trap\Processable;
use Buggregator\Trap\Sender;
use Buggregator\Trap\Sender\Websocket\ConnectionPool;

/**
 * @internal
 */
final class WebsocketSender implements Sender, Processable
{
    public static function create(
        ?ConnectionPool $connectionPool = null,
    ): self {
        $connectionPool ??= new ConnectionPool();
        return new self($connectionPool, new Websocket\FrameHandler($connectionPool));
    }

    private function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly FrameHandler $handler,
    ) {
    }

    public function send(iterable $frames): void
    {
        foreach ($frames as $frame) {
            $this->handler->handle($frame);
        }
    }

    public function getConnectionPool(): ConnectionPool
    {
        return $this->connectionPool;
    }

    public function process(): void
    {
        $this->connectionPool->process();
    }
}
