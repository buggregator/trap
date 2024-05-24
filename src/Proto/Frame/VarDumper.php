<?php

declare(strict_types=1);

namespace Buggregator\Trap\Proto\Frame;

use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\ProtoType;

/**
 * @internal
 * @psalm-internal Buggregator
 */
final class VarDumper extends Frame
{
    public function __construct(
        public readonly string $dump,
        \DateTimeImmutable $time = new \DateTimeImmutable(),
    ) {
        parent::__construct(ProtoType::VarDumper, $time);
    }

    public static function fromString(string $payload, \DateTimeImmutable $time): static
    {
        return new self($payload, $time);
    }

    public function __toString(): string
    {
        return $this->dump;
    }
}
