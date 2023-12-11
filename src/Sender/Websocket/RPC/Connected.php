<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Websocket\RPC;

use Buggregator\Trap\Info;
use JsonSerializable;

/**
 * @internal
 */
final class Connected implements JsonSerializable
{
    public function __construct(
        public readonly string|int $id,
        public readonly string $client,
        public readonly int $ping = 25,
        public readonly bool $pong = true,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'connect' => [
                'client' => $this->client,
                'version' => Info::VERSION,
                'subs' => [
                    'events' => (object)[],
                ],
                'ping' => $this->ping,
                'pong' => $this->pong,
            ],
        ];
    }
}
