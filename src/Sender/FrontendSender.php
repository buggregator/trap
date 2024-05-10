<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender;

use Buggregator\Trap\Logger;
use Buggregator\Trap\Processable;
use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Sender;
use Buggregator\Trap\Sender\Frontend\ConnectionPool;

/**
 * @internal
 */
final class FrontendSender implements Sender, Processable
{
    private function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly Frontend\EventStorage $framesStorage,
        private readonly FrameHandler $handler,
    ) {}

    public static function create(
        Logger $logger,
        ?ConnectionPool $connectionPool = null,
        ?Frontend\EventStorage $eventStorage = null,
    ): self {
        $eventStorage ??= new Frontend\EventStorage();
        $connectionPool ??= new ConnectionPool($logger, new Frontend\RPC($logger, $eventStorage));

        return new self(
            $connectionPool,
            $eventStorage,
            new Frontend\FrameHandler($logger, $connectionPool, $eventStorage),
        );
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

    public function getEventStorage(): Frontend\EventStorage
    {
        return $this->framesStorage;
    }

    public function process(): void
    {
        $this->connectionPool->process();
    }
}
