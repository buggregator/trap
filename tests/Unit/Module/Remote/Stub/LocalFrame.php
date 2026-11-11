<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Remote\Stub;

use Buggregator\Trap\Server\Proto\Frame;
use Buggregator\Trap\Server\Sender\LocalOnlyFrame;

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
