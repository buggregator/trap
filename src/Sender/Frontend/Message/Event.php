<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Frontend\Message;

final class Event implements \JsonSerializable
{
    /**
     * @param non-empty-string $uuid
     * @param non-empty-string $type
     */
    public function __construct(
        public readonly string $uuid,
        public readonly string $type,
        public readonly array $payload,
        public readonly float $timestamp,
        public readonly ?string $projectId = null,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'uuid' => $this->uuid,
            'type' => $this->type,
            'payload' => $this->payload,
            'timestamp' => $this->timestamp,
            'projectId' => $this->projectId,
        ];
    }
}
