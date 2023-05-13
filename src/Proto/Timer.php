<?php

declare(strict_types=1);

namespace Buggregator\Client\Proto;

class Timer
{
    private float $start;

    /**
     * @param null|float $beep Seconds
     */
    public function __construct(
        public ?float $beep = null,
    ) {
        $this->reset();
    }

    public function reset(): void
    {
        $this->start = \microtime(true);
    }

    public function isReady(): bool
    {
        return $this->beep !== null && $this->elapsed() > $this->beep;
    }

    public function elapsed(): float
    {
        return \microtime(true) - $this->start;
    }
}