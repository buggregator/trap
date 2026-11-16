<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Frontend\Stub;

use Buggregator\Trap\Module\HttpDump\Frame;
use Buggregator\Trap\Server\Event\ProvidesEvents;

/**
 * Takes the frame class and the event type of the HTTP dump module.
 */
final class TakingHttpUi implements ProvidesEvents
{
    public function eventMappers(): array
    {
        return [Frame::class => BinaryMapper::class];
    }

    public function eventTypes(): array
    {
        return ['http-dump'];
    }
}
