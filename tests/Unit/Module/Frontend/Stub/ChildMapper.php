<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Frontend\Stub;

use Buggregator\Trap\Module\Frontend\Event;
use Buggregator\Trap\Module\Frontend\EventMapper;
use Buggregator\Trap\Server\Proto\Frame;

final class ChildMapper implements EventMapper
{
    public function map(Frame $frame): Event
    {
        return new Event('c1', 'child', [], 1.0);
    }
}
