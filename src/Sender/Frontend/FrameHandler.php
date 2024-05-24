<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Frontend;

use Buggregator\Trap\Logger;
use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Sender\FrameHandler as HandlerInterface;
use Buggregator\Trap\Sender\Frontend\Message\Push;
use Buggregator\Trap\Support\Json;

/**
 * @internal
 */
final class FrameHandler implements HandlerInterface
{
    private readonly FrameMapper $frameMapper;

    public function __construct(
        private readonly Logger $logger,
        private readonly ConnectionPool $connectionPool,
        private readonly EventStorage $eventsStorage,
    ) {
        $this->frameMapper = new FrameMapper();
    }

    public function handle(Frame $frame): void
    {
        try {
            $this->eventsStorage->add($event = $this->frameMapper->map($frame));
            unset($frame);
        } catch (\Throwable $e) {
            $this->logger->error('Mapping frame failed: %s', $e->getMessage());
            return;
        }

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
