<?php

declare(strict_types=1);

namespace Buggregator\Trap\Traffic\Parser;

use Buggregator\Trap\Support\Stream\Base64DecodeFilter;
use Buggregator\Trap\Support\StreamHelper;
use Buggregator\Trap\Traffic\Message\Multipart\Field;
use Buggregator\Trap\Traffic\Message\Multipart\File;
use Buggregator\Trap\Traffic\Message\Multipart\Part;
use Buggregator\Trap\Traffic\StreamClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;

/**
 * @internal
 */
final class Http
{
    private const MAX_URL_ENCODED_BODY_SIZE = 4194304; // 4MB

    private Psr17Factory $factory;

    public function __construct()
    {
        $this->factory = new Psr17Factory();
    }

    /**
     * Get text block before empty line
     */
    public static function getBlock(StreamClient $stream): string
    {
        $previous = $block = '';
        while (!$stream->isFinished()) {
            $line = $stream->fetchLine();
            if ($line === "\r\n" && \str_ends_with($previous, "\r\n")) {
                return \substr($block, 0, -2);
            }
            $previous = $line;
            $block .= $line;
        }

        return $block;
    }

    /**
     * @return array<array-key, non-empty-list<non-empty-string>>
     */
    public static function parseHeaders(string $headersBlock): array
    {
        $result = [];
        foreach (\explode("\r\n", $headersBlock) as $line) {
            [$name, $value] = \explode(':', $line, 2) + [1 => ''];
            $name = \trim($name);
            $value = \trim($value);
            if ($name === '' || $value === '') {
                continue;
            }

            $result[$name][] = $value;
        }

        return $result;
    }

    public static function parseUrlEncodedBody(ServerRequestInterface $request): ServerRequestInterface
    {
        if ($request->getBody()->getSize() > self::MAX_URL_ENCODED_BODY_SIZE) {
            return $request;
        }

        $str = $request->getBody()->__toString();

        try {
            \parse_str($str, $parsed);
            return $request->withParsedBody($parsed);
        } catch (\Throwable) {
            return $request;
        }
    }

    /**
     * @param non-empty-string $boundary
     *
     * @return iterable<Part>
     */
    public static function parseMultipartBody(StreamInterface $stream, string $boundary): iterable
    {
        $result = [];
        $findBoundary = "--{$boundary}";
        try {
            while (false !== ($pos = StreamHelper::strpos($stream, $findBoundary))) {
                $stream->seek($pos + \strlen($findBoundary), \SEEK_CUR);
                $blockEnd = StreamHelper::strpos($stream, "\r\n\r\n");
                // End of valid content
                if ($blockEnd === false || $blockEnd - $pos <= 2) {
                    break;
                }
                // Parse part headers
                $headers = self::parseHeaders($stream->read($blockEnd - $pos + 2));

                $part = Part::create($headers);

                $stream->seek(2, \SEEK_CUR); // Skip \r\n
                $findBoundary = "\r\n--{$boundary}";

                if ($part instanceof File) {
                    $writeFilters = [];
                    if ($part->hasHeader('Content-Transfer-Encoding')) {
                        $encoding = $part->getHeaderLine('Content-Transfer-Encoding');
                        $encoding === 'base64' and $writeFilters[] = Base64DecodeFilter::FILTER_NAME;
                    }

                    $fileStream = StreamHelper::createFileStream(writeFilters: $writeFilters);
                    StreamHelper::writeStreamUntil($stream, $fileStream, $findBoundary);
                    $part->setStream($fileStream);
                } elseif ($part instanceof Field) {
                    $endOfContent = StreamHelper::strpos($stream, $findBoundary);
                    $endOfContent !== false or throw new \RuntimeException('Missing end of content');

                    $part = $part->withValue($endOfContent > 0 ? $stream->read($endOfContent) : '');
                }
                $result[] = $part;
            }
        } catch (\Throwable $e) {
            // throw $e;
        }

        return $result;
    }

    public function parseStream(StreamClient $stream): ServerRequestInterface
    {
        $firstLine = $stream->fetchLine();
        $headersBlock = self::getBlock($stream);

        [$method, $uri, $protocol] = $this->parseFirstLine($firstLine);
        $headers = self::parseHeaders($headersBlock);

        $request = $this->factory->createServerRequest($method, $uri, [])
            ->withProtocolVersion($protocol);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader((string) $name, $value);
        }

        // Todo refactor:
        //  - move to separated method
        //  - add tests
        //  - special chars like `;` can be in double quotes that why we can't use just explode
        //
        // See https://developer.mozilla.org/en-US/docs/Web/HTTP/Cookies
        //
        // Format (https://datatracker.ietf.org/doc/html/rfc6265#section-4.2.1):
        // cookie-header = "Cookie:" OWS cookie-string OWS
        // cookie-string = cookie-pair *( ";" SP cookie-pair )
        // cookie-pair       = cookie-name "=" cookie-value
        // cookie-value      = *cookie-octet / ( DQUOTE *cookie-octet DQUOTE )
        // cookie-octet      = %x21 / %x23-2B / %x2D-3A / %x3C-5B / %x5D-7E
        //                ; US-ASCII characters excluding CTLs,
        //                ; whitespace DQUOTE, comma, semicolon,
        //                ; and backslash
        if ($request->hasHeader('Cookie')) {
            $rawCookies = \explode(';', $request->getHeaderLine('Cookie'));
            $cookies = [];
            foreach ($rawCookies as $cookie) {
                if (\str_contains($cookie, '=')) {
                    /** @psalm-suppress PossiblyUndefinedArrayOffset */
                    [$name, $value] = \explode('=', \trim($cookie), 2);
                    $cookies[$name] = $value;
                }
            }

            $request = $request->withCookieParams($cookies);
        }

        return $this->parseBody($stream, $request);
    }

    /**
     * @param string $line
     *
     * @return array{non-empty-string, non-empty-string, non-empty-string}
     */
    private function parseFirstLine(string $line): array
    {
        $parts = \explode(' ', \trim($line));
        if (\count($parts) !== 3) {
            throw new \InvalidArgumentException('Invalid first line.');
        }

        $parts[2] = \explode('/', $parts[2], 2)[1] ?? $parts[2];
        if ($parts[0] === '' || $parts[1] === '' || $parts[2] === '') {
            throw new \InvalidArgumentException('Invalid first line.');
        }

        return $parts;
    }

    private function parseBody(StreamClient $stream, ServerRequestInterface $request): ServerRequestInterface
    {
        // Methods have body
        if (!\in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $request;
        }

        // Guess length
        $length = $request->hasHeader('Content-Length') ? $request->getHeaderLine('Content-Length') : null;
        $length = \is_numeric($length) ? (int) $length : null;

        $request = $request->withBody($this->createBody($stream, $length));
        $request->getBody()->rewind();

        // Decode encoded content
        if ($request->hasHeader('Content-Encoding')) {
            $encoding = $request->getHeaderLine('Content-Encoding');
            if ($encoding === 'gzip') {
                $request = StreamHelper::unzipBody($request);
            }
        }

        $contentType = $request->getHeaderLine('Content-Type');
        return match (true) {
            $contentType === 'application/x-www-form-urlencoded' => self::parseUrlEncodedBody($request),
            \str_contains($contentType, 'multipart/') => $this->processMultipartForm($request),
            default => $request,
        };
    }

    private function processMultipartForm(ServerRequestInterface $request): ServerRequestInterface
    {
        if (\preg_match('/boundary="?([^"\\s;]++)"?/', $request->getHeaderLine('Content-Type'), $matches) !== 1) {
            return $request;
        }
        /** @var non-empty-string $boundary */
        $boundary = $matches[1];

        $parts = self::parseMultipartBody($request->getBody(), $boundary);
        $uploadedFiles = $parsedBody = [];
        foreach ($parts as $part) {
            $name = $part->getName();
            if ($name === null) {
                continue;
            }
            if ($part instanceof Field) {
                $parsedBody[$name] = $part->getValue();
                continue;
            }
            if ($part instanceof File) {
                $uploadedFiles[$name][] = new UploadedFile(
                    $part->getStream(),
                    (int) $part->getSize(),
                    $part->getError(),
                    $part->getClientFilename(),
                    $part->getClientMediaType(),
                );
            }
        }

        return $request->withUploadedFiles($uploadedFiles)->withParsedBody($parsedBody);
    }

    /**
     * Flush stream data PSR stream.
     * Note: there can be read more data than {@see $limit} bytes but write only {@see $limit} bytes.
     */
    private function createBody(StreamClient $stream, ?int $limit): StreamInterface
    {
        $fileStream = StreamHelper::createFileStream();
        $written = 0;

        foreach ($stream->getIterator() as $chunk) {
            if ($limit !== null && \strlen($chunk) + $written >= $limit) {
                $fileStream->write(\substr($chunk, 0, $limit - $written));
                return $fileStream;
            }

            // Check trailed double \r\n
            if ($limit === null && !$stream->hasData() && \str_ends_with($chunk, "\r\n\r\n")) {
                $fileStream->write(\substr($chunk, 0, -4));
                return $fileStream;
            }

            $fileStream->write($chunk);
            $written += \strlen($chunk);
            unset($chunk);
            \Fiber::suspend();
        }

        return $fileStream;
    }
}
