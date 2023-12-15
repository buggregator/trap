<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender;

use Buggregator\Trap\Logger;
use Buggregator\Trap\Processable;
use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Sender\Frontend\ConnectionPool;

/**
 * @internal
 */
final class FrontendSender implements \Buggregator\Trap\Sender, Processable
{
    public static function create(
        Logger $logger,
        ?Frontend\ConnectionPool $connectionPool = null,
        ?Frontend\EventsStorage $eventStorage = null,
    ): self {
        $eventStorage ??= new Frontend\EventsStorage();
        $connectionPool ??= new Frontend\ConnectionPool($logger, new Frontend\RPC($logger, $eventStorage));
        return new self(
            $connectionPool,
            $eventStorage,
            new Frontend\FrameHandler($connectionPool, $eventStorage),
        );
    }

    private function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly Frontend\EventsStorage $framesStorage,
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
     * @return Frontend\EventsStorage
     */
    public function getEventStorage(): Frontend\EventsStorage
    {
        return $this->framesStorage;
    }

    public function process(): void
    {
        $this->connectionPool->process();
    }
}
