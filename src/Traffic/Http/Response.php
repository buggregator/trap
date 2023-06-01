<?php

declare(strict_types=1);

namespace Buggregator\Client\Traffic\Http;

use Psr\Http\Message\ResponseInterface;

final class Response
{
    public static function fromPsr7(ResponseInterface $response): self
    {
        return new self(
            $response->getStatusCode(),
            $response->getReasonPhrase(),
            (string)$response->getBody(),
            $response->getHeaders(),
        );
    }

    /**
     * @param array<non-empty-string, non-empty-string[]> $headers
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly string $statusText,
        public readonly string $body = '',
        public array $headers = [],
    ) {
        $this->headers['Content-Length'] = [\strlen($this->body)];
    }

    public function __toString(): string
    {
        return \sprintf('HTTP/%s %d %s', '1.1', $this->statusCode, $this->statusText) . "\r\n"
            . \implode(
                "\r\n",
                \array_map(
                    static fn(string $key, array $values): string => "$key: " . \implode(', ', $values),
                    \array_keys($this->headers),
                    \array_values($this->headers)
                )
            )
            . "\r\n\r\n"
            . $this->body;
    }
}
