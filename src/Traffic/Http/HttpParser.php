<?php

declare(strict_types=1);

namespace Buggregator\Client\Traffic\Http;

use Generator;
use Http\Message\Encoding\GzipDecodeStream;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/**
 * @psalm-type LinesGenerator = Generator<int, string, mixed, void>
 */
class HttpParser
{
    private Psr17Factory $factory;

    public function __construct()
    {
        $this->factory = new Psr17Factory();
    }

    /**
     * @param LinesGenerator $generator Generator that yields lines only with trailing \r\n
     */
    public function parseStream(Generator $generator): ServerRequestInterface
    {
        $firstLine = $generator->current();
        $generator->next();

        $headersBlock = $this->getBlock($generator);

        [$method, $uri, $protocol] = self::parseFirstLine($firstLine);
        $headers = $this->parseHeaders($headersBlock);

        $request = $this->factory->createServerRequest($method, $uri, [])
            ->withProtocolVersion($protocol);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->parseBody($generator, $request);
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
     * @param LinesGenerator $generator
     *
     * @return string
     */
    private function getBlock(Generator $generator): string
    {
        $previous = $block = '';
        while ($generator->valid()) {
            $line = $generator->current();
            if ($line === "\r\n" && \str_ends_with($previous, "\r\n")) {
                return \substr($block, 0, -2);
            }
            $generator->next();
            $previous = $line;

            $block .= $line;
        }

        return $block;
    }

    /**
     * Read around {@see $bytes} bytes.
     *
     * @param LinesGenerator $generator
     */
    private function getBytes(Generator $generator, int $bytes): string
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

    /**
     * @param LinesGenerator $stream
     */
    private function parseBody(Generator $stream, ServerRequestInterface $request): ServerRequestInterface
    {
        // Methods have body
        if (!\in_array($request->getMethod(), ['POST', 'PUT', 'PATCH'], true)) {
            return $request;
        }
        $stream->next(); // Skip previous empty line

        // Guess length
        $length = $request->hasHeader('Content-Length') ? $request->getHeaderLine('Content-Length') : null;
        $length = \is_numeric($length) ? (int) $length : null;

        // todo resolve very large body using a stream
        $request = $request->withBody($this->factory->createStream(
            $length !== null
                ? $this->getBytes($stream, $length)
                // Try to read body block without Content-Length
                : $this->getBlock($stream)
        ));

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
                $isFile = $fileName || isset($headers['Content-Type']);

                $stream->seek(2, \SEEK_CUR); // Skip \r\n
                $findBoundary = "\r\n--{$boundary}";
                $endOfContent = StreamHelper::strpos($stream, $findBoundary);
                $endOfContent !== false or throw new RuntimeException('Missing end of content');

                if ($isFile) {
                    $fileStream = $this->factory->createStream();
                    if ($endOfContent > 0) {
                        StreamHelper::writeStream($stream, $fileStream, $endOfContent);
                    }
                    $uploadedFiles[$name] = new UploadedFile(
                        $fileStream,
                        $endOfContent,
                        \UPLOAD_ERR_OK,
                        $fileName,
                        $headers['Content-Type'][0] ?? null,
                    );
                } else {
                    $parsedBody[$name] = $endOfContent > 0 ? $stream->read($endOfContent) : '';
                }
            }
        } catch (\Throwable) {
        }

        return $requset->withUploadedFiles($uploadedFiles)->withParsedBody($parsedBody);
    }

    private function unzipBody(ServerRequestInterface $request): ServerRequestInterface
    {
        $stream =new GzipDecodeStream($request->getBody());
        return $request->withBody($stream);
    }
}
