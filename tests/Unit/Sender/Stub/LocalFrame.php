<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Sender\Stub;

use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Sender\LocalOnlyFrame;

final class LocalFrame extends Frame implements LocalOnlyFrame
{
    public function __construct()
    {
        parent::__construct('local');
    }

    public static function fromString(string $payload, \DateTimeImmutable $time): static
    {
        return new self();
    }

    public function __toString(): string
    {
        return '';
    }
}
