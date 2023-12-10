<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Websocket;

final class Event
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
}
