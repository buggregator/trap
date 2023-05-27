<?php

declare(strict_types=1);

namespace Buggregator\Client;

use Buggregator\Client\Proto\Frame;

interface Sender
{
    /**
     * @param iterable<int, Frame> $frames
     */
    public function send(iterable $frames): void;
}
