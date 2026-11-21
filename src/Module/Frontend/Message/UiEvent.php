<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Message;

use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\EventStore;

/**
 * An event as the UI gets it: with the state the store keeps for it.
 *
 * @internal
 */
final class UiEvent implements \JsonSerializable
{
    public function __construct(
        public readonly Event $event,
        public readonly bool $pinned = false,
    ) {}

    public static function of(Event $event, EventStore $store): self
    {
        return new self($event, $store->isPinned($event->uuid));
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->event->jsonSerialize() + ['is_pinned' => $this->pinned];
    }
}
