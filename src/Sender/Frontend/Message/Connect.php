<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Frontend\Message;

use Buggregator\Trap\Info;

/**
 * @internal
 */
final class Connect implements \JsonSerializable
{
    public function __construct(
        public readonly string $client,
        public readonly int $ping = 25,
        public readonly bool $pong = true,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'client' => $this->client,
            'version' => Info::version(),
            'subs' => [
                'events' => (object) [],
            ],
            'ping' => $this->ping,
            'pong' => $this->pong,
        ];
    }
}
