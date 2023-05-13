<?php

declare(strict_types=1);

namespace Buggregator\Client\Proto;

class Buffer
{
    /** @var string[] */
    private array $frames = [];
    private int $currentSize = 0;
    private ?Timer $timer;

    public function __construct(
        public int $bufferSize,
        ?float $timer = null,
    ) {
        $this->timer = $timer === null ? null : new Timer(beep: 1.5);
    }

    public function addFrame(Frame $frame): void
    {
        $str = (string)$frame;
        $this->frames[] = $str;
        $this->currentSize += \strlen($str);

        $this->timer->continue();
    }

    public function getAndClean(): string
    {
        $result = '[' . \implode(",\n", $this->frames) . ']';
        $this->frames = [];
        $this->currentSize = 0;
        $this->timer->stop();

        return $result;
    }

    public function isReady(): bool
    {
        return $this->isOverflow() || $this->timer?->isReady();
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