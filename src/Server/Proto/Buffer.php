<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Proto;

use Buggregator\Trap\Support\Timer;

/**
 * A buffer that accumulates events and notifies when it is full.
 * Overflow can occur due to a configured timeout or number of events.
 *
 * @internal
 */
final class Buffer
{
    /** @var Frame[] */
    private array $frames = [];

    /** @var int<0, max> Current payload size */
    private int $currentSize = 0;

    private ?Timer $timer;

    /**
     * @param int $bufferSize Payload limit size in bytes
     * @param null|float $timer Seconds after the first frame until the buffer is ready; null disables the timer
     */
    public function __construct(
        public int $bufferSize,
        ?float $timer = null,
    ) {
        $this->timer = $timer === null ? null : new Timer(beep: $timer);
        $this->timer?->stop();
    }

    public function addFrame(Frame $frame): void
    {
        $this->frames[] = $frame;
        $this->currentSize += $frame->getSize();

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
