<?php

declare(strict_types=1);

namespace Buggregator\Client\Sender;

use Buggregator\Client\Proto\Frame;
use Buggregator\Client\Sender;
use Buggregator\Client\Sender\Console\HandlerInterface;

final class ConsoleSender implements Sender
{
    public function __construct(
        private readonly HandlerInterface $handler,
    ) {
    }

    /**
     * @param iterable<int, Frame> $frames
     */
    public function send(iterable $frames): void
    {
        foreach ($frames as $frame) {
            $this->handler->handle($frame);
        }
    }
}
