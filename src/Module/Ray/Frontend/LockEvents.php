<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Ray\Frontend;

use Buggregator\Trap\Module\Ray\Lock\LockState;
use Buggregator\Trap\Server\Event\Event;
use Buggregator\Trap\Server\Event\EventStore;

/**
 * The released state of a pause in the stored Ray events: `content.state` of their `create_lock` payloads.
 *
 * The events keep the state after the lock itself expires, so a reloaded page shows it, and the store
 * pushes an updated event to every UI client.
 *
 * @internal
 */
final class LockEvents
{
    public function __construct(
        private readonly EventStore $events,
    ) {}

    /**
     * Writes the state into every Ray event that pauses on the lock: clients pausing in the same second share
     * one lock name, and one release lets them all go on.
     *
     * @return int<0, max> The number of updated events.
     */
    public function release(string $name, LockState $state): int
    {
        $count = 0;
        foreach (\iterator_to_array($this->events) as $event) {
            $payload = $event->payload;
            if ($event->type !== Mapper::EVENT_TYPE || !$payload instanceof Payload) {
                continue;
            }

            $changed = false;
            $payloads = $payload->payloads;
            foreach ($payloads as $i => $item) {
                /** @var mixed $content */
                $content = $item['content'] ?? null;
                if (self::lockName($item) === $name && \is_array($content) && ($content['state'] ?? null) !== $state->value) {
                    $content['state'] = $state->value;
                    $payloads[$i]['content'] = $content;
                    $changed = true;
                }
            }

            if (!$changed) {
                continue;
            }

            /** @var Event<array|\JsonSerializable> $updated */
            $updated = new Event(
                uuid: $event->uuid,
                type: $event->type,
                payload: new Payload($payload->uuid, $payloads, $payload->meta),
                timestamp: $event->timestamp,
                project: $event->project,
                assets: $event->assets,
                facets: $event->facets,
            );
            $this->events->add($updated);
            ++$count;
        }

        return $count;
    }

    /**
     * @return LockState|null The released state a stored event keeps for the lock; null if none does.
     */
    public function stateOf(string $name): ?LockState
    {
        foreach ($this->events as $event) {
            $payload = $event->payload;
            if ($event->type !== Mapper::EVENT_TYPE || !$payload instanceof Payload) {
                continue;
            }

            foreach ($payload->payloads as $item) {
                /** @var mixed $state */
                $state = self::lockName($item) === $name ? $item['content']['state'] ?? null : null;
                $state = \is_string($state) ? LockState::tryFrom($state) : null;
                if ($state !== null && $state !== LockState::Active) {
                    return $state;
                }
            }
        }

        return null;
    }

    private static function lockName(array $payload): ?string
    {
        /** @var mixed $content */
        $content = $payload['content'] ?? null;
        /** @var mixed $name */
        $name = \is_array($content) ? $content['name'] ?? null : null;

        return ($payload['type'] ?? null) === 'create_lock' && \is_string($name) ? $name : null;
    }
}
