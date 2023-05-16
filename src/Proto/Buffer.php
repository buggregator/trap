<?php

declare(strict_types=1);

namespace Buggregator\Client\Proto;

use Buggregator\Client\Support\Timer;

class Buffer
{
    /** @var Frame[] */
    private array $frames = [];
    /** @var int<0, max> Current payload size */
    private int $currentSize = 0;
    private ?Timer $timer;

    /**
     * @param int $bufferSize Payload limit size in bytes
     */
    public function __construct(
        public int $bufferSize,
        ?float $timer = null,
    ) {
        $this->timer = $timer === null ? null : new Timer(beep: 0.1);
        $this->timer?->stop();
    }

    public function addFrame(Frame $frame): void
    {
        $this->frames[] = $frame;
        $this->currentSize += \strlen($frame->__toString());

        $this->timer?->continue();
    }

    /**
     * @return Frame[]
     */
    public function getAndClean(): array
    {
        $result = $this->frames;
        // Clear buffer
        $this->frames = [];
        $this->currentSize = 0;
        $this->timer?->stop();

        return $result;
    }

    public function isReady(): bool
    {
        return $this->isOverflow() || $this->timer?->isReady() === true;
    }

    public function getSize(): int
    {
        return $this->currentSize;
    }

    public function isOverflow(): bool
    {
        return $this->currentSize > $this->bufferSize;
    }
}
