<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender;

use Buggregator\Trap\Logger;
use Buggregator\Trap\Module\Frontend;
use Buggregator\Trap\Processable;
use Buggregator\Trap\Proto\Frame;

/**
 * @internal
 */
final class FrontendSender implements \Buggregator\Trap\Sender, Processable
{
    private function __construct(
        private readonly Frontend\ConnectionPool $connectionPool,
        private readonly Frontend\EventStorage $framesStorage,
        private readonly FrameHandler $handler,
    ) {}

    public static function create(
        Logger $logger,
        ?Frontend\ConnectionPool $connectionPool = null,
        ?Frontend\EventStorage $eventStorage = null,
    ): self {
        $eventStorage ??= new Frontend\EventStorage();
        $connectionPool ??= new Frontend\ConnectionPool($logger, new Frontend\RPC($logger, $eventStorage));
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

    public function getConnectionPool(): Frontend\ConnectionPool
    {
        return $this->connectionPool;
    }

    /**
     * @return Frontend\EventStorage
     */
    public function getEventStorage(): Frontend\EventStorage
    {
        return $this->framesStorage;
    }

    public function process(): void
    {
        $this->connectionPool->process();
    }
}
