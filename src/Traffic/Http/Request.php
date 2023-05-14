<?php

declare(strict_types=1);

namespace Buggregator\Client\Traffic\Http;

class Request
{
    /**
     * @param 'GET'|'POST'|'PUT'|'HEAD'|'OPTIONS' $method
     * @param non-empty-string $uri
     * @param non-empty-string $protocol
     * @param array<non-empty-string, list<non-empty-string>> $headers Header names are in lower case
     * @param string $body
     */
    public function __construct(
        public string $method,
        public string $uri,
        public string $protocol,
        public array $headers,
        public string $body
    ) {
    }
}
