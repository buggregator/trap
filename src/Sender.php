<?php

declare(strict_types=1);

namespace Buggregator\Trap;

use Buggregator\Trap\Proto\Frame;

interface Sender
{
    /**
     * @param iterable<int, Frame> $frames
     */
    public function send(iterable $frames): void;
}
