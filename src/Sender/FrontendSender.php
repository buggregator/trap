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
    public function __construct(
        private readonly Frontend\ConnectionPool $connectionPool,
        private readonly Frontend\EventStorage $framesStorage,
        private readonly Frontend\FrameHandler $handler,
    ) {}

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
