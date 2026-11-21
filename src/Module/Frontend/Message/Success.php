<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Message;

/**
 * @internal
 */
final class Success implements \JsonSerializable, HttpCode
{
    public function __construct(
        public readonly int $code = 200,
        public readonly bool $status = true,
    ) {}

    #[\Override]
    public function httpCode(): int
    {
        return $this->code;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'code' => $this->code,
            'status' => $this->status,
        ];
    }
}
