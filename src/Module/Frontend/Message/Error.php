<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Message;

/**
 * @internal
 */
final class Error implements \JsonSerializable, HttpCode
{
    public function __construct(
        public readonly string $message,
        public readonly int $code = 404,
    ) {}

    public static function notFound(string $message = 'Not found'): self
    {
        return new self($message, 404);
    }

    #[\Override]
    public function httpCode(): int
    {
        return $this->code;
    }

    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'message' => $this->message,
            'code' => $this->code,
        ];
    }
}
