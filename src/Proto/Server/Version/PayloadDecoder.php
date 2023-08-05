<?php

declare(strict_types=1);

namespace Buggregator\Trap\Proto\Server\Version;

use Buggregator\Trap\Proto\Server\Request;

interface PayloadDecoder
{
    public function isSupport(string $payload): bool;
    public function decode(string $payload): Request;
}
