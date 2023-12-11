<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Websocket;

use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Sender\FrameHandler as HandlerInterface;
use Buggregator\Trap\Sender\Websocket\RPC\Push;
use Buggregator\Trap\Support\Json;

/**
 * @internal
 */
final class FrameHandler implements HandlerInterface
{
    private readonly FrameMapper $frameMapper;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly EventsStorage $eventsStorage,
    ) {
        $this->frameMapper = new FrameMapper();
    }

    public function handle(Frame $frame): void
    {
        $this->eventsStorage->add($event = $this->frameMapper->map($frame));
        unset($frame);

        // Send event to all connections
        $this->connectionPool->send(\Buggregator\Trap\Traffic\Websocket\Frame::text(
            Json::encode(
                new Push(
                    event: 'event.received',
                    channel: 'events',
                    data: $event,
                ),
            ),
        ));
    }
}
