<?php

declare(strict_types=1);

namespace Buggregator\Client\Traffic\Http;

class Request
{
    public function __construct(
        public string $method,
        public string $uri,
        public string $protocol,
        public array $headers,
        public string $body
    ) {
    }
}
