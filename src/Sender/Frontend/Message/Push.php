<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Frontend\Message;

/**
 * @internal
 */
final class Push implements \JsonSerializable
{
    public function __construct(
        public readonly string $event,
        public readonly string $channel,
        public readonly mixed $data,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'push' => [
                'channel' => $this->channel,
                'pub' => [
                    'data' => [
                        'event' => $this->event,
                        'data' => $this->data,
                    ],
                ],
            ],
        ];
    }
}
