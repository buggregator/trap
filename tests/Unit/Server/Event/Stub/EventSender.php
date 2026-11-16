<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Event\Stub;

use Buggregator\Trap\Server\Proto\Frame;
use Buggregator\Trap\Server\Sender;
use Buggregator\Trap\Server\Sender\ConsumesEvents;

/**
 * Keeps the frames it gets.
 */
final class EventSender implements Sender, ConsumesEvents
{
    /** @var list<Frame> */
    public array $frames = [];

    public function send(iterable $frames): void
    {
        foreach ($frames as $frame) {
            $this->frames[] = $frame;
        }
    }
}
