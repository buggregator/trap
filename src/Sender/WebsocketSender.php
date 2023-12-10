<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender;

use Buggregator\Trap\Logger;
use Buggregator\Trap\Processable;
use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Sender\Websocket\ConnectionPool;

/**
 * @internal
 */
final class WebsocketSender implements \Buggregator\Trap\Sender, Processable
{
    public static function create(
        Logger $logger,
        ?Websocket\ConnectionPool $connectionPool = null,
        ?Websocket\EventsStorage $eventStorage = null,
    ): self {
        $connectionPool ??= new Websocket\ConnectionPool($logger);
        $eventStorage ??= new Websocket\EventsStorage();
        return new self(
            $connectionPool,
            $eventStorage,
            new Websocket\FrameHandler($connectionPool, $eventStorage),
        );
    }

    private function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly Websocket\EventsStorage $framesStorage,
        private readonly FrameHandler $handler,
    ) {
    }

    /**
     * @param iterable<Frame> $frames
     */
    public function send(iterable $frames): void
    {
        foreach ($frames as $frame) {
            \assert($frame instanceof Frame);
            $this->handler->handle($frame);
        }
    }

    public function getConnectionPool(): ConnectionPool
    {
        return $this->connectionPool;
    }

    /**
     * @return Websocket\EventsStorage
     */
    public function getEventStorage(): Websocket\EventsStorage
    {
        return $this->framesStorage;
    }

    public function process(): void
    {
        $this->connectionPool->process();
    }
}
