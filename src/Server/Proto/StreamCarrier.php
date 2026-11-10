<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Proto;

use Psr\Http\Message\StreamInterface;

/**
 * @internal
 * @psalm-internal Buggregator
 */
interface StreamCarrier
{
    public function getStream(): ?StreamInterface;
}
