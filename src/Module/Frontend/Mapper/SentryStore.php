<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Mapper;

use Buggregator\Trap\Module\Frontend\Event;
use Buggregator\Trap\Proto\Frame\Sentry\SentryStore as SentryFrame;
use Buggregator\Trap\Support\Uuid;

/**
 * @internal
 */
final class SentryStore
{
    public function map(SentryFrame $frame): Event
    {
        return new Event(
            uuid: Uuid::uuid4(),
            type: 'sentry',
            payload: $frame->message,
            timestamp: (float) $frame->time->format('U.u'),
        );
    }
}
