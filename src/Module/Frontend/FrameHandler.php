<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend;

use Buggregator\Trap\Logger;
use Buggregator\Trap\Module\Frontend\Message\Push;
use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Sender\FrameHandler as HandlerInterface;
use Buggregator\Trap\Support\Json;

/**
 * @internal
 */
final class FrameHandler implements HandlerInterface
{
    public function __construct(
        private readonly Logger $logger,
        private readonly ConnectionPool $connectionPool,
        private readonly EventStorage $eventsStorage,
        private readonly FrameMapper $frameMapper,
    ) {}

    #[\Override]
    public function handle(Frame $frame): void
    {
        try {
            $event = $this->frameMapper->map($frame);
            if ($event === null) {
                return;
            }

            $this->eventsStorage->add($event);
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
