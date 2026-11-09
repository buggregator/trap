<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Frontend\Stub;

use Buggregator\Trap\Module\Frontend\Event;
use Buggregator\Trap\Module\Frontend\EventMapper;
use Buggregator\Trap\Proto\Frame;

final class BinaryMapper implements EventMapper
{
    public function map(Frame $frame): Event
    {
        return new Event('b1', 'binary', [], 1.0);
    }
}
