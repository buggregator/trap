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

    abstract static public function fromString(string $payload, DateTimeImmutable $time): self;

    /**
     * @return int<0, max>
     */
    public function getSize(): int
    {
        return \strlen((string)$this);
    }

    public final function jsonSerialize(): array
    {
        return [
            'time' => $this->time->format('Y-m-d H:i:s.u'),
            'type' => $this->type,
            'data' => \base64_encode($this->__toString()),
        ];
    }
}
