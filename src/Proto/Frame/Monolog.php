<?php

declare(strict_types=1);

namespace Buggregator\Trap\Proto\Frame;

use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\ProtoType;
use DateTimeImmutable;

/**
 * @internal
 * @psalm-internal Buggregator
 */
final class Monolog extends Frame
{
    public function __construct(
        public readonly array $message,
        DateTimeImmutable $time = new DateTimeImmutable()
    ) {
        parent::__construct(ProtoType::Monolog, $time);
    }

    /**
     * @throws \JsonException
     */
    public function __toString(): string
    {
        return \json_encode($this->message, JSON_THROW_ON_ERROR);
    }

    public static function fromString(string $payload, DateTimeImmutable $time): Frame
    {
        return new self(
            \json_decode($payload, true, JSON_THROW_ON_ERROR),
            $time
        );
    }
}
