<?php

declare(strict_types=1);

namespace Buggregator\Client\Traffic\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;

class HttpParser
{
    private ServerRequestFactoryInterface $factory;

    public function __construct() {
        $this->factory = new Psr17Factory();
    }

    /**
     * @param \Generator<int, string, mixed, void> $generator Generator that yields lines only with trailing \r\n
     */
    public function parseStream(\Generator $generator): ServerRequestInterface
    {
        $firstLine = $generator->current();
        $generator->next();

        $headersBlock = self::getBlock($generator);

        [$method, $uri, $protocol] = self::parseFirstLine($firstLine);
        $headers = self::parseHeaders($headersBlock);

        $requset = $this->factory->createServerRequest($method, $uri, [])
            ->withProtocolVersion($protocol);
        foreach ($headers as $name => $value) {
            $requset = $requset->withHeader($name, $value);
        }

        // Parse body
        $requset = $this->parseBody($generator, $requset);

        return $requset;
    }

    /**
     * @param string $line
     *
     * @return array{0: non-empty-string, 1: non-empty-string, 2: non-empty-string}
     */
    private function parseFirstLine(string $line): array
    {
        $parts = \explode(' ', \trim($line));
        if (\count($parts) !== 3) {
            throw new \InvalidArgumentException('Invalid first line.');
        }
        $parts[2] = \explode('/', $parts[2], 2)[1] ?? $parts[2];

        return $parts;
    }

    /**
     * Get text block before empty line
     *
     * @param \Generator<int, string, mixed, void> $generator
     *
     * @return string
     */
    private static function getBlock(\Generator $generator): string
    {
        $block = '';
        while ($generator->valid()) {
            $line = $generator->current();
            if ($line === "\r\n") {
                break;
            }
            $generator->next();

            $block .= $line;
        }

        return $block;
    }

    /**
     * @param non-empty-string $headersBlock
     *
     * @return array<non-empty-string, list<non-empty-string>>
     */
    private static function parseHeaders(string $headersBlock): array
    {
        $result = [];
        foreach (\explode("\r\n", $headersBlock) as $line) {
            if (!\str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = \explode(':', $line, 2);

            $result[\trim($name)][] = \trim($value);
        }

        return $result;
    }

    private function parseBody(\Generator $generator, ServerRequestInterface $requset): ServerRequestInterface
    {
        // Methods have body
        if (!\in_array($requset->getMethod(), ['POST', 'PUT', 'PATCH'], true)) {
            return $requset;
        }

        if (!$requset->hasHeader('Content-Type') || $requset->getHeaderLine('Content-Type') === 'application/x-www-form-urlencoded') {
            return $this->parseUrlEncodedBody($generator, $requset);
        }

        return $requset;
    }

    private function parseUrlEncodedBody(\Generator $stream, ServerRequestInterface $requset): ServerRequestInterface
    {
        $stream->next();
        $str = \rtrim($stream->current());
        \parse_str($str, $parsed);

        return $requset->withBody($this->factory->createStream($str))->withParsedBody($parsed);
    }
}
