<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Websocket\RPC;

use JsonSerializable;

/**
 * @internal
 */
final class Success implements JsonSerializable
{
    public function __construct(
        public readonly string|int $id,
        public readonly int $code,
        public readonly bool $status,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'rpc' => [
                'data' => [
                    'code' => $this->code,
                    'status' => $this->status,
                ],
            ],
        ];
    }
}
