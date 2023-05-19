<?php

declare(strict_types=1);

namespace Buggregator\Client\Traffic\Http;

use Buggregator\Client\Socket\StreamClient;
use Buggregator\Client\Support\StreamHelper;
use Http\Message\Encoding\GzipDecodeStream;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final class HttpParser
{
    private const MAX_URL_ENCODED_BODY_SIZE = 4194304; // 4MB

    private Psr17Factory $factory;

    public function __construct()
    {
        $this->factory = new Psr17Factory();
    }

    public function parseStream(StreamClient $stream): ServerRequestInterface
    {
        $firstLine = $stream->fetchLine();
        $headersBlock = $this->getBlock($stream);

        [$method, $uri, $protocol] = $this->parseFirstLine($firstLine);
        $headers = $this->parseHeaders($headersBlock);

        $request = $this->factory->createServerRequest($method, $uri, [])
            ->withProtocolVersion($protocol);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
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
                [$name, $value] = \explode('=', \trim($cookie), 2);
                $cookies[$name] = $value;
            }

            $request = $request->withCookieParams($cookies);
        }

        return $this->parseBody($stream, $request);
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
     */
    private function getBlock(StreamClient $stream): string
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
     * Read around {@see $bytes} bytes.
     */
    private function getBytes(StreamClient $stream, int $bytes): string
    {
        if ($bytes <= 0) {
            return '';
        }
        $block = '';
        foreach ($stream->getIterator() as $chunk) {
            $block .= $chunk;
            if (\strlen($block) >= $bytes) {
                break;
            }
        }

        return $block;
    }

    /**
     * @param non-empty-string $headersBlock
     *
     * @return array<non-empty-string, list<non-empty-string>>
     */
    private function parseHeaders(string $headersBlock): array
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

    private function parseBody(StreamClient $stream, ServerRequestInterface $request): ServerRequestInterface
    {
        // Methods have body
        if (!\in_array($request->getMethod(), ['POST', 'PUT', 'PATCH'], true)) {
            return $request;
        }

        // Guess length
        $length = $request->hasHeader('Content-Length') ? $request->getHeaderLine('Content-Length') : null;
        $length = \is_numeric($length) ? (int)$length : null;

        // todo resolve very large body using a stream
        $request = $request->withBody(
            $this->factory->createStream(
                $length !== null
                    ? $this->getBytes($stream, $length)
                    // Try to read body block without Content-Length
                    : $this->getBlock($stream)
            )
        );

        // Decode encoded content
        if ($request->hasHeader('Content-Encoding')) {
            $encoding = $request->getHeaderLine('Content-Encoding');
            if ($encoding === 'gzip') {
                $request = $this->unzipBody($request);
            }
        }

        $contentType = $request->getHeaderLine('Content-Type');
        return match (true) {
            $contentType === 'application/x-www-form-urlencoded' => $this->parseUrlEncodedBody($request),
            \str_contains($contentType, 'multipart/form-data') => $this->parseMultipartBody($request),
            default => $request,
        };
    }

    private function parseUrlEncodedBody(ServerRequestInterface $request): ServerRequestInterface
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

    private function parseMultipartBody(ServerRequestInterface $requset): ServerRequestInterface
    {
        if (\preg_match('/boundary=([^\\s;]++)/', $requset->getHeaderLine('Content-Type'), $matches) !== 1) {
            return $requset;
        }
        $boundary = $matches[1];
        $stream = $requset->getBody();

        $uploadedFiles = [];
        $parsedBody = [];
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
                $headers = $this->parseHeaders($stream->read($blockEnd - $pos + 2));

                // Check Content-Disposition header
                $contentDisposition = $headers['Content-Disposition'][0]
                    ?? throw new RuntimeException('Missing Content-Disposition header');
                // Get field name and file name
                $name = \preg_match('/\bname="([^"]++)"/', $contentDisposition, $matches) === 1
                    ? $matches[1]
                    : throw new RuntimeException('Missing name in Content-Disposition header');
                $fileName = \preg_match('/\bfilename="([^"]++)"/', $contentDisposition, $matches) === 1 ? $matches[1]
                    : null;
                $fileName = $fileName !== null ? \html_entity_decode($fileName) : null;
                $isFile = $fileName || isset($headers['Content-Type']);

                $stream->seek(2, \SEEK_CUR); // Skip \r\n
                $findBoundary = "\r\n--{$boundary}";

                if ($isFile) {
                    $fileStream = $this->factory->createStream();
                    $fileSize = StreamHelper::writeStreamUntil($stream, $fileStream, $findBoundary);

                    $uploadedFiles[$name][] = new UploadedFile(
                        $fileStream,
                        $fileSize,
                        \UPLOAD_ERR_OK,
                        $fileName,
                        $headers['Content-Type'][0] ?? null,
                    );
                } else {
                    $endOfContent = StreamHelper::strpos($stream, $findBoundary);
                    $endOfContent !== false or throw new RuntimeException('Missing end of content');
                    $parsedBody[$name] = $endOfContent > 0 ? $stream->read($endOfContent) : '';
                }
            }
        } catch (\Throwable) {
        }

        return $requset->withUploadedFiles($uploadedFiles)->withParsedBody($parsedBody);
    }

    private function unzipBody(ServerRequestInterface $request): ServerRequestInterface
    {
        $stream = new GzipDecodeStream($request->getBody());
        return $request->withBody($stream);
    }
}
