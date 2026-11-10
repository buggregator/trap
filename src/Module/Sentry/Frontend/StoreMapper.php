<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Sentry\Frontend;

use Buggregator\Trap\Module\Frontend\Event;
use Buggregator\Trap\Module\Frontend\EventMapper;
use Buggregator\Trap\Module\Sentry\Frame\SentryStore as SentryFrame;
use Buggregator\Trap\Server\Proto\Frame;
use Buggregator\Trap\Support\Uuid;

/**
 * @internal
 */
final class StoreMapper implements EventMapper
{
    #[\Override]
    public function map(Frame $frame): Event
    {
        \assert($frame instanceof SentryFrame);

        return new Event(
            uuid: Uuid::uuid4(),
            type: 'sentry',
            payload: $frame->message,
            timestamp: (float) $frame->time->format('U.u'),
        );
    }
}
