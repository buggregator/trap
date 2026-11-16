<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Event\Stub;

use Buggregator\Trap\Server\Event\ProvidesEvents;
use Buggregator\Trap\Server\Proto\Frame\Binary;

final class EventModule implements ProvidesEvents
{
    public function eventMappers(): array
    {
        return [Binary::class => CountingMapper::class];
    }

    public function eventTypes(): array
    {
        return ['binary'];
    }
}
