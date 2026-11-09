<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Sentry\Frontend;

use Buggregator\Trap\Module\Frontend\Event;
use Buggregator\Trap\Module\Sentry\Frame\SentryStore as SentryFrame;
use Buggregator\Trap\Support\Uuid;

/**
 * @internal
 */
final class StoreMapper
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
