<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Websocket\RPC;

use JsonSerializable;

/**
 * @internal
 */
final class Push implements JsonSerializable
{
    public function __construct(
        public readonly string $event,
        public readonly string $channel,
        public readonly mixed $data,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'channel' => $this->channel,
            'pub' => [
                'data' => [
                    'data' => $this->data,
                    'event' => $this->event,
                ],
            ],
        ];
    }
}
