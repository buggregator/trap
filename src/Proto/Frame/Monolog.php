<?php

declare(strict_types=1);

namespace Buggregator\Trap\Proto\Frame;

use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\ProtoType;
use Buggregator\Trap\Support\Json;

/**
 * @internal
 * @psalm-internal Buggregator
 */
final class Monolog extends Frame
{
    public function __construct(
        public readonly array $message,
        \DateTimeImmutable $time = new \DateTimeImmutable(),
    ) {
        parent::__construct(ProtoType::Monolog, $time);
    }

    #[\Override]
    public static function fromString(string $payload, \DateTimeImmutable $time): static
    {
        /** @psalm-suppress MixedArgument Bug #240: JSON_THROW_ON_ERROR is passed as the depth, so invalid JSON gives null instead of an exception */
        return new self(
            \json_decode($payload, true, JSON_THROW_ON_ERROR),
            $time,
        );
    }

    /**
     * @throws \JsonException
     */
    public function __toString(): string
    {
        return Json::encode($this->message);
    }
}
