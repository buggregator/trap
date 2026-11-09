<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\VarDumper;

use Buggregator\Trap\Proto\Frame as ProtoFrame;

/**
 * @internal
 * @psalm-internal Buggregator
 */
final class Frame extends ProtoFrame
{
    public const TYPE = 'var-dumper';

    public function __construct(
        public readonly string $dump,
        \DateTimeImmutable $time = new \DateTimeImmutable(),
    ) {
        parent::__construct(self::TYPE, $time);
    }

    #[\Override]
    public static function fromString(string $payload, \DateTimeImmutable $time): static
    {
        return new self($payload, $time);
    }

    public function __toString(): string
    {
        return $this->dump;
    }
}
