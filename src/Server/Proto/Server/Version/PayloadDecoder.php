<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Proto\Server\Version;

use Buggregator\Trap\Server\Proto\Server\Request;

/**
 * @internal
 * @psalm-internal Buggregator
 */
interface PayloadDecoder
{
    public function isSupport(string $payload): bool;

    public function decode(string $payload): Request;
}
