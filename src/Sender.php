<?php

declare(strict_types=1);

namespace Buggregator\Trap;

use Buggregator\Trap\Proto\Frame;

/**
 * @internal
 */
interface Sender
{
    /**
     * @param iterable<array-key, Frame> $frames
     */
    public function send(iterable $frames): void;
}
