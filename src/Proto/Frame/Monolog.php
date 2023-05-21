<?php

declare(strict_types=1);

namespace Buggregator\Client\Proto\Frame;

use Buggregator\Client\Proto\Frame;
use Buggregator\Client\ProtoType;
use DateTimeImmutable;

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

    static public function fromString(string $payload, DateTimeImmutable $time): Frame
    {
        return new self(
            \json_decode($payload, true, JSON_THROW_ON_ERROR),
            $time
        );
    }
}
