<?php

declare(strict_types=1);

namespace Buggregator\Client\Proto;

use RuntimeException;

class Timer
{
    private float $start;
    private bool $stop = false;

    /**
     * @param null|float $beep Seconds
     */
    public function __construct(
        public ?float $beep = null,
    ) {
        $this->reset();
    }

    public function stop(): void
    {
        $this->stop = true;
    }

    public function reset(): void
    {
        $this->start = \microtime(true);
        $this->stop = false;
    }

    public function isReady(): bool
    {
        return !$this->stop && $this->beep !== null && $this->elapsed() > $this->beep;
    }

    public function elapsed(): float
    {
        return $this->stop ? throw new RuntimeException('Timer stopped.') : \microtime(true) - $this->start;
    }

    /**
     * Reset timer if it's stopped.
     */
    public function continue(): void
    {
        if ($this->stop) {
            $this->reset();
        }
    }
}