<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Event\Stub;

use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\EventMapper;
use Buggregator\Trap\Server\Proto\Frame;

/**
 * Maps each frame to a new event `e{N}`, N counting the calls.
 */
final class CountingMapper implements EventMapper
{
    /** @var int<0, max> */
    public int $calls = 0;

    public function map(Frame $frame): Event
    {
        ++$this->calls;

        return new Event('e' . $this->calls, 'binary', [], (float) $this->calls);
    }
}
