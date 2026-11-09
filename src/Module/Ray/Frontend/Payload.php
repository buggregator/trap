<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Ray\Frontend;

/**
 * Payload of the `ray` UI event: the client request, with the payloads of the chained calls added.
 *
 * @internal
 */
final class Payload implements \JsonSerializable
{
    /**
     * @param non-empty-string $uuid
     * @param list<array> $payloads
     */
    public function __construct(
        public readonly string $uuid,
        public readonly array $payloads,
        public readonly array $meta,
    ) {}

    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'uuid' => $this->uuid,
            'payloads' => $this->payloads,
            'meta' => (object) $this->meta,
        ];
    }
}
