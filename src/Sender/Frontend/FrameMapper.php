<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Frontend;

use Buggregator\Trap\Proto\Frame;
use IteratorAggregate;

/**
 * @internal
 * @implements IteratorAggregate<Frame>
 */
final class FrameMapper
{
    public function map(Frame $frame): Event
    {
        return match ($frame::class) {
            Frame\VarDumper::class => (new Mapper\VarDump())->map($frame),
            default => throw new \InvalidArgumentException('Unknown frame type ' . $frame::class),
        };
    }
}
