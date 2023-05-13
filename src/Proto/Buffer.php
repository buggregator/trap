<?php

declare(strict_types=1);

namespace Buggregator\Client\Proto;

class Buffer
{
    /** @var string[] */
    private array $frames;
    private int $currentSize = 0;

    public function __construct(
        public int $bufferSize,
    ) {
    }

    public function addFrame(Frame $frame): void
    {
        $str = (string)$frame;
        $this->frames[] = $str;
        $this->currentSize += \strlen($str);
    }

    public function getSize(): int
    {
        return $this->currentSize;
    }

    public function isOverflow(): bool
    {
        return $this->currentSize > $this->bufferSize;
    }

    public function getAndClean(): string
    {
        $result = '[' . \implode(",\n", $this->frames) . ']';
        $this->frames = [];
        $this->currentSize = 0;

        return $result;
    }
}