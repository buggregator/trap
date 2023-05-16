<?php

declare(strict_types=1);

namespace Buggregator\Client\Proto;

use Buggregator\Client\ProtoType;
use DateTimeImmutable;

abstract class Frame implements \Stringable, \JsonSerializable
{
    public function __construct(
        public readonly ProtoType $type,
        public readonly DateTimeImmutable $time = new DateTimeImmutable(),
    ) {
    }

    public final function jsonSerialize(): array
    {
        return [
            'time' => $this->time->format('Y-m-d H:i:s.u'),
            'type' => $this->type,
            'data' => \base64_decode($this->__toString()),
        ];
    }
}
