<?php

declare(strict_types=1);

namespace Buggregator\Trap\Proto;

use Buggregator\Trap\ProtoType;

/**
 * @internal
 * @psalm-internal Buggregator
 */
abstract class Frame implements \Stringable, \JsonSerializable
{
    public function __construct(
        public readonly ProtoType $type,
        public readonly \DateTimeImmutable $time = new \DateTimeImmutable(),
    ) {}

    abstract public static function fromString(string $payload, \DateTimeImmutable $time): static;

    /**
     * @return int<0, max>
     */
    public function getSize(): int
    {
        return \strlen((string) $this);
    }

    final public function jsonSerialize(): array
    {
        return [
            'time' => $this->time->format('Y-m-d H:i:s.u'),
            'type' => $this->type,
            'data' => \base64_encode($this->__toString()),
        ];
    }
}
