<?php

declare(strict_types=1);

namespace Buggregator\Trap\Proto\Frame\Sentry;

use Buggregator\Trap\Support\Json;

/**
 * @internal
 * @psalm-internal Buggregator
 */
final class EnvelopeItem implements \Stringable, \JsonSerializable
{
    public function __construct(
        public readonly array $headers,
        public readonly mixed $payload,
    ) {}

    public function jsonSerialize(): mixed
    {
        return [
            'headers' => $this->headers,
            'payload' => $this->payload,
        ];
    }

    /**
     * @throws \JsonException
     */
    public function __toString(): string
    {
        return Json::encode($this->jsonSerialize());
    }
}
