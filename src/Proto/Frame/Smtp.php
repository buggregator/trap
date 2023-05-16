<?php

declare(strict_types=1);

namespace Buggregator\Client\Proto\Frame;

use Buggregator\Client\Proto\Frame;
use Buggregator\Client\ProtoType;
use DateTimeImmutable;

final class Smtp extends Frame
{
    public function __construct(
        public readonly string $message,
        DateTimeImmutable $time = new DateTimeImmutable()
    ) {
        parent::__construct(ProtoType::SMTP, $time);
    }

    /**
     * @throws \JsonException
     */
    public function __toString(): string
    {
        return $this->message;
    }
}
