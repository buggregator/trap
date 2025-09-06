<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend;

use Buggregator\Trap\Module\Frontend\Event\Asset;

/**
 * @template TPayload as \JsonSerializable
 * @internal
 */
final class Event implements \JsonSerializable
{
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

    public function jsonSerialize(): array
    {
        return [
            'uuid' => $this->uuid,
            'type' => $this->type,
            'payload' => $this->payload,
            'timestamp' => $this->timestamp,
            'project' => $this->project,
        ];
    }
}
