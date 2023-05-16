<?php

declare(strict_types=1);

namespace Buggregator\Client\Proto\Frame;

use Buggregator\Client\Proto\Frame;
use Buggregator\Client\ProtoType;
use DateTimeImmutable;

final class VarDumper extends Frame
{
    public function __construct(
        public readonly string $dump,
        DateTimeImmutable $time = new DateTimeImmutable()
    ) {
        parent::__construct(ProtoType::VarDumper, $time);
    }

    /**
     * @throws \JsonException
     */
    public function __toString(): string
    {
        return $this->dump;
    }
}
