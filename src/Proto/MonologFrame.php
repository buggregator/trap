<?php

declare(strict_types=1);

namespace Buggregator\Client\Proto;

use Buggregator\Client\ProtoType;
use DateTimeImmutable;

final class MonologFrame extends Frame
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
}
