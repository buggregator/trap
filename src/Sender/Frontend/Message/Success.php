<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Frontend\Message;

/**
 * @internal
 */
final class Success implements \JsonSerializable
{
    public function __construct(
        public readonly int $code = 200,
        public readonly bool $status = true,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'code' => $this->code,
            'status' => $this->status,
        ];
    }
}
