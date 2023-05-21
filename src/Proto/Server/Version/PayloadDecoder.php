<?php

declare(strict_types=1);

namespace Buggregator\Client\Proto\Server\Version;

use Buggregator\Client\Proto\Server\Request;

interface PayloadDecoder
{
    public function isSupport(string $payload): bool;
    public function decode(string $payload): Request;
}
