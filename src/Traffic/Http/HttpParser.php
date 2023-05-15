<?php

declare(strict_types=1);

namespace Buggregator\Client\Traffic\Http;

use App\Application\HTTP\GzippedStream;
use Buggregator\Client\Logger;
use GuzzleHttp\Psr7\Stream;
use Http\Message\Encoding\GzipDecodeStream;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;

class HttpParser
{
    private Psr17Factory $factory;

    public function __construct()
    {
        $this->factory = new Psr17Factory();
    }

    /**
     * @param \Generator<int, string, mixed, void> $generator Generator that yields lines only with trailing \r\n
     */
    public function parseStream(\Generator $generator): ServerRequestInterface
    {
        $firstLine = $generator->current();
        $generator->next();

        $headersBlock = $this->getBlock($generator);

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
    private function getBlock(\Generator $generator): string
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
     * Read around {@see $bytes} bytes.
     *
     * @param \Generator<int, string, mixed, void> $generator
     */
    private function getBytes(\Generator $generator, int $bytes): string
    {
        if ($bytes <= 0) {
            return '';
        }
        $block = '';
        while ($generator->valid()) {
            $line = $generator->current();
            $block .= $line;
            if (\strlen($block) >= $bytes) {
                break;
            }

            $generator->next();
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

    /**
     * @param \Generator<int, string, mixed, void> $stream
     */
    private function parseBody(\Generator $stream, ServerRequestInterface $request): ServerRequestInterface
    {
        // Methods have body
        if (!\in_array($request->getMethod(), ['POST', 'PUT', 'PATCH'], true)) {
            return $request;
        }
        $stream->next(); // Skip previous empty line

        // Guess length
        $length = $request->hasHeader('Content-Length') ? $request->getHeaderLine('Content-Length') : null;
        $length = \is_numeric($length) ? (int) $length : null;

        // todo resolve very large body in a stream
        $request = $request->withBody($this->factory->createStream(
            $length !== null
                ? $this->getBytes($stream, $length)
                // Try to read body block without Content-Length
                : $this->getBlock($stream)
        ));

        // Encoded content
        if ($request->hasHeader('Content-Encoding')) {
            $encoding = $request->getHeaderLine('Content-Encoding');
            if ($encoding === 'gzip') {
                $request = $this->unzipBody($request);
            }
        }

        $contentType = $request->getHeaderLine('Content-Type');
        return match (true) {
            $contentType === 'application/x-www-form-urlencoded' => $this->parseUrlEncodedBody($request),
            \str_contains($contentType, 'multipart/form-data') => $this->parseMultipartBody($stream, $request),
            default => $request,
        };
    }

    private function parseUrlEncodedBody(ServerRequestInterface $requset): ServerRequestInterface
    {
        $str = $requset->getBody()->__toString();
        try {
            \parse_str($str, $parsed);
            return $requset->withParsedBody($parsed);
        } catch (\Throwable) {
            return $requset;
        }
    }

    private function parseMultipartBody(\Generator $stream, ServerRequestInterface $requset): ServerRequestInterface
    {
        if (\preg_match('/boundary=([^\\s;]++)/', $requset->getHeaderLine('Content-Type'), $matches) !== 1) {
            return $requset;
        }
        $boundary = $matches[1];

        // todo
        // Logger::dump(substr($requset->getBody()->__toString(), 0, 100)); die;
        // Logger::dump($boundary); die;

        return $requset;
    }

    // todo
    private function unzipBody(ServerRequestInterface $request): ServerRequestInterface
    {
        // $content = (string)$request->getBody();
        //
        // $resource = fopen('php://temp', 'r+b');
        // \fwrite($resource, $content);
        // \rewind($resource);
        // $stream = \Nyholm\Psr7\Stream::create($resource);
        //
        // $stream =new GzipDecodeStream($stream);

        // Logger::dump($stream->__toString()); die;

        $stream =new GzipDecodeStream($request->getBody());
        Logger::dump(substr($stream->__toString(), 0, 100)); die;
        return $request->withBody($stream);
    }
}
