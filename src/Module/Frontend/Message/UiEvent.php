<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Message;

use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Server\Event\Previewable;

/**
 * An event as the UI gets it: with the state the store keeps for it.
 *
 * @internal
 */
final class UiEvent implements \JsonSerializable
{
    /**
     * @param bool $preview For a list or the live feed: a {@see Previewable} payload gives its preview.
     */
    public function __construct(
        public readonly Event $event,
        public readonly bool $pinned = false,
        public readonly bool $preview = false,
    ) {}

    public static function of(Event $event, EventStore $store, bool $preview = false): self
    {
        return new self($event, $store->isPinned($event->uuid), $preview);
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        $data = $this->event->jsonSerialize();
        $this->preview && $this->event->payload instanceof Previewable
            and $data['payload'] = $this->event->payload->preview();

        return $data
            + ['is_pinned' => $this->pinned]
            + ($this->event->facets === [] ? [] : ['facets' => $this->event->facets]);
    }
}
