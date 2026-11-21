<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Message;

use Buggregator\Trap\Server\Info;

/**
 * @internal
 */
final class Connect implements \JsonSerializable
{
    /**
     * @param list<non-empty-string> $channels Channels the server subscribes the client to.
     */
    public function __construct(
        public readonly string $client,
        public readonly array $channels = [],
        public readonly int $ping = 25,
        public readonly bool $pong = true,
    ) {}

    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'client' => $this->client,
            'version' => Info::version(),
            'subs' => (object) \array_fill_keys($this->channels, (object) []),
            'ping' => $this->ping,
            'pong' => $this->pong,
        ];
    }
}
