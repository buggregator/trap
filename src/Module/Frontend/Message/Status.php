<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Message;

/**
 * A success of a status other than 200: `201` with the created resource, `204` with no body.
 *
 * @internal
 */
final class Status implements \JsonSerializable, HttpCode
{
    public const NO_CONTENT = 204;

    public function __construct(
        public readonly int $code,
        public readonly mixed $data = null,
    ) {}

    public static function created(mixed $data): self
    {
        return new self(201, $data);
    }

    public static function noContent(): self
    {
        return new self(self::NO_CONTENT);
    }

    #[\Override]
    public function httpCode(): int
    {
        return $this->code;
    }

    #[\Override]
    public function jsonSerialize(): mixed
    {
        return $this->data;
    }
}
