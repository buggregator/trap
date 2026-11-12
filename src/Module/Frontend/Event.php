<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend;

use Buggregator\Trap\Module\Frontend\Event\Asset;

/**
 * @template TPayload of array|\JsonSerializable
 * @internal
 */
final class Event implements \JsonSerializable
{
    /**
     * Project of the events sent without one. The UI shows only the events of the active project,
     * and it picks the default project when there is nothing else.
     */
    public const DEFAULT_PROJECT = 'default';

    /**
     * @param non-empty-string $uuid
     * @param non-empty-string $type
     * @param TPayload $payload
     * @param \ArrayAccess<non-empty-string, Asset>&\Traversable<non-empty-string, Asset>|null $assets
     */
    public function __construct(
        public readonly string $uuid,
        public readonly string $type,
        public readonly array|\JsonSerializable $payload,
        public readonly float $timestamp,
        public readonly ?string $project = null,
        public readonly ?\ArrayAccess $assets = null,
    ) {}

    /**
     * @return non-empty-string
     */
    public function projectKey(): string
    {
        return $this->project === null || $this->project === '' ? self::DEFAULT_PROJECT : $this->project;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'uuid' => $this->uuid,
            'type' => $this->type,
            'payload' => $this->payload,
            'timestamp' => $this->timestamp,
            'project' => $this->projectKey(),
        ];
    }
}
