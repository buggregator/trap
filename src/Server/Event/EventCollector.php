<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Event;

use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Server\Proto\Frame;

/**
 * Maps the caught frames to events and keeps them in the {@see EventStore}, each frame once whatever
 * the number of event consumers.
 *
 * The application runs it only when a chosen sender is {@see \Buggregator\Trap\Server\Sender\ConsumesEvents}.
 *
 * @internal
 */
final class EventCollector
{
    public function __construct(
        private readonly Logger $logger,
        private readonly FrameMapper $mapper,
        private readonly EventStore $store,
    ) {}

    /**
     * @param iterable<array-key, Frame> $frames
     */
    public function collect(iterable $frames): void
    {
        foreach ($frames as $frame) {
            try {
                $event = $this->mapper->map($frame);
                $event === null or $this->store->add($event, $frame);
            } catch (\Throwable $e) {
                $this->logger->error('Mapping frame failed: %s', $e->getMessage());
            }
        }
    }
}
