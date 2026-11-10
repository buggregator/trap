<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Sentry\Frontend;

use Buggregator\Trap\Module\Frontend\Event;
use Buggregator\Trap\Module\Frontend\EventMapper;
use Buggregator\Trap\Module\Sentry\Frame\SentryEnvelope as SentryFrame;
use Buggregator\Trap\Server\Proto\Frame;
use Buggregator\Trap\Support\Uuid;

/**
 * @internal
 */
final class EnvelopeMapper implements EventMapper
{
    #[\Override]
    public function map(Frame $frame): Event
    {
        \assert($frame instanceof SentryFrame);

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
