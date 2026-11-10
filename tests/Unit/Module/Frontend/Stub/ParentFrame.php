<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Frontend\Stub;

use Buggregator\Trap\Proto\Frame;

/**
 * A frame class a module maps; {@see ChildFrame} extends it.
 */
class ParentFrame extends Frame
{
    public const TYPE = 'parent';

    public function __construct(\DateTimeImmutable $time = new \DateTimeImmutable())
    {
        parent::__construct(self::TYPE, $time);
    }

    #[\Override]
    public static function fromString(string $payload, \DateTimeImmutable $time): static
    {
        return new static($time);
    }

    #[\Override]
    public function __toString(): string
    {
        return '';
    }
}
