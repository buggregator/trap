<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Frontend\Message;

/**
 * @internal
 */
final class Rpc implements \JsonSerializable
{
    public function __construct(
        public readonly mixed $data,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'data' => $this->data,
        ];
    }
}
