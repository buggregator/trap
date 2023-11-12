<?php

declare(strict_types=1);

namespace Buggregator\Trap\Proto\Frame\Sentry;

/**
 * @internal
 * @psalm-internal Buggregator
 */
final class EnvelopeItem implements \Stringable, \JsonSerializable
{
    public function __construct(
        public readonly array $headers,
        public readonly mixed $payload,
    ) {
    }

    /**
     * @throws \JsonException
     */
    public function __toString(): string
    {
        return \json_encode($this->jsonSerialize(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function jsonSerialize(): mixed
    {
        return [
            'headers' => $this->headers,
            'payload' => $this->payload,
        ];
    }
}
