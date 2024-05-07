<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Frontend\Mapper;

use Buggregator\Trap\Proto\Frame\Sentry\SentryEnvelope as SentryFrame;
use Buggregator\Trap\Sender\Frontend\Event;
use Buggregator\Trap\Support\Uuid;

/**
 * @internal
 */
final class SentryEnvelope
{
    public function map(SentryFrame $frame): Event
    {
        // todo: add support for multiple items
        isset($frame->items[0]) or throw new \InvalidArgumentException('SentryEnvelope has no items.');
        \is_array($frame->items[0]->payload) or throw new \InvalidArgumentException(
            'SentryEnvelope payload is not an array.',
        );

        return new Event(
            uuid: Uuid::uuid4(),
            type: 'sentry',
            payload: $frame->items[0]->payload,
            timestamp: (float) $frame->time->format('U.u'),
        );
    }
}
