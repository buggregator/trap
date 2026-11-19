<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Traffic\Parser;

use Buggregator\Trap\Server\Traffic\Message\Multipart\Field;
use Buggregator\Trap\Server\Traffic\Message\Multipart\File;
use Buggregator\Trap\Server\Traffic\Message\Multipart\Part;
use Buggregator\Trap\Server\Traffic\StreamClient;
use Buggregator\Trap\Support\Stream\Base64DecodeFilter;
use Buggregator\Trap\Support\Stream\QuotedPrintableDecodeFilter;
use Buggregator\Trap\Support\Stream\SizeLimitExceeded;
use Buggregator\Trap\Support\StreamHelper;
use Buggregator\Trap\Support\TimeSlice;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;

/**
 * @internal
 */
final class Http
{
    /** The largest body a module accepts: an Xdebug profile */
    public const MAX_INFLATED_BODY_SIZE = 64 * 1024 * 1024;

    private const MAX_URL_ENCODED_BODY_SIZE = 4194304; // 4MB

    private Psr17Factory $factory;

    /** @var int<1, max> */
    private readonly int $maxInflatedBodySize;

    /**
     * @param int $maxInflatedBodySize Limit of a compressed body after inflating and of a chunked body
     *        after decoding, in bytes; values below 1 fall back to {@see MAX_INFLATED_BODY_SIZE}.
     */
    public function __construct(int $maxInflatedBodySize = self::MAX_INFLATED_BODY_SIZE)
    {
        $this->maxInflatedBodySize = $maxInflatedBodySize > 0 ? $maxInflatedBodySize : self::MAX_INFLATED_BODY_SIZE;
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
                if ($blockEnd === false || $blockEnd <= 2) {
                    break;
                }
                // Parse part headers; a folded line continues the previous header
                $headers = self::parseHeaders((string) \preg_replace("/\r\n(?=[ \t])/", '', $stream->read($blockEnd + 2)));

                $part = Part::create($headers);

                $stream->seek(2, \SEEK_CUR); // Skip \r\n
                $findBoundary = "\r\n--{$boundary}";

                if ($part instanceof File) {
                    $writeFilters = match (\strtolower(\trim($part->getHeaderLine('Content-Transfer-Encoding')))) {
                        'base64' => [Base64DecodeFilter::FILTER_NAME],
                        'quoted-printable' => [QuotedPrintableDecodeFilter::FILTER_NAME],
                        default => [],
                    };

                    $part->setStream(StreamHelper::copyUntilThroughFilters($stream, $findBoundary, $writeFilters));
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

    /**
     * @throws InvalidRequest
     */
    public function parseStream(StreamClient $stream): ServerRequestInterface
    {
        $firstLine = $stream->fetchLine();
        $headersBlock = self::getBlock($stream);

        [$method, $uri, $protocol] = $this->parseFirstLine($firstLine);
        $headers = self::parseHeaders($headersBlock);

        try {
            // Not through `parse_url()`: it reads `//mcp` as the host `mcp` and `/user:42` as a host with a port
            if (\str_starts_with($uri, '/')) {
                [$uri, $fragment] = \explode('#', $uri, 2) + [1 => ''];
                [$path, $query] = \explode('?', $uri, 2) + [1 => ''];
                $uri = $this->factory->createUri()->withPath($path)->withQuery($query)->withFragment($fragment);
            }

            $request = $this->factory->createServerRequest($method, $uri, []);
        } catch (\InvalidArgumentException) {
            // Not chained: the URI parser quotes the target in its message, and the target may carry a secret
            throw new InvalidRequest('Invalid request target.');
        }

        $request = $request->withProtocolVersion($protocol);
        try {
            foreach ($headers as $name => $value) {
                $request = $request->withHeader((string) $name, $value);
            }
        } catch (\InvalidArgumentException) {
            // Neither chained nor naming the header: its name or value may carry a secret
            throw new InvalidRequest('Invalid header.');
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
     * @return array{non-empty-string, non-empty-string, non-empty-string}
     */
    private function parseFirstLine(string $line): array
    {
        $parts = \explode(' ', \trim($line));
        if (\count($parts) !== 3) {
            throw new InvalidRequest('Invalid first line.');
        }

        $parts[2] = \explode('/', $parts[2], 2)[1] ?? $parts[2];
        if ($parts[0] === '' || $parts[1] === '' || $parts[2] === '') {
            throw new InvalidRequest('Invalid first line.');
        }

        return $parts;
    }

    private function parseBody(StreamClient $stream, ServerRequestInterface $request): ServerRequestInterface
    {
        // Methods have body
        if (!\in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $request;
        }

        $codings = $this->getTransferCodings($request);
        if (\in_array('chunked', $codings, true)) {
            // HTTP/1.1 allows `chunked` only once and only as the last coding: elsewhere the framing is unknown
            \array_pop($codings) === 'chunked' && !\in_array('chunked', $codings, true)
                or throw new InvalidRequest('Invalid transfer coding.');

            // Before `Content-Length`: `Transfer-Encoding` overrides it
            $request = $this->decodeTransferCodings($stream, $request, $codings);
        } else {
            // Guess length
            $length = $request->hasHeader('Content-Length') ? $request->getHeaderLine('Content-Length') : null;
            $length = \is_numeric($length) ? (int) $length : null;
            // HTTP/1.1: a request with neither `Content-Length` nor `Transfer-Encoding` has no body
            $length === null && $codings === [] and $length = 0;

            $request = $request->withBody($this->createBody($stream, $length));
            $request->getBody()->rewind();
        }

        // Decode encoded content
        if ($request->hasHeader('Content-Encoding')) {
            $encoding = $request->getHeaderLine('Content-Encoding');
            if ($encoding === 'gzip') {
                $request = StreamHelper::unzipBody($request, $this->maxInflatedBodySize);
            }
        }

        $contentType = $request->getHeaderLine('Content-Type');
        return match (true) {
            $contentType === 'application/x-www-form-urlencoded' => self::parseUrlEncodedBody($request),
            \str_contains($contentType, 'multipart/') => $this->processMultipartForm($request),
            default => $request,
        };
    }

    /**
     * @return list<non-empty-lowercase-string> Transfer codings in the order they were applied.
     */
    private function getTransferCodings(ServerRequestInterface $request): array
    {
        $codings = [];
        foreach ($request->getHeader('Transfer-Encoding') as $value) {
            foreach (\explode(',', $value) as $coding) {
                // Parameters of a coding don't matter to the codings decoded here
                $coding = \strtolower(\trim(\explode(';', $coding, 2)[0]));
                $coding === '' or $codings[] = $coding;
            }
        }

        return $codings;
    }

    /**
     * Read a chunked body and undo the transfer codings, leaving the request as if it came with `Content-Length`.
     *
     * @param list<non-empty-lowercase-string> $codings Transfer codings applied before `chunked`.
     *
     * @throws InvalidRequest
     * @throws SizeLimitExceeded
     */
    private function decodeTransferCodings(
        StreamClient $stream,
        ServerRequestInterface $request,
        array $codings,
    ): ServerRequestInterface {
        $request = $request->withBody($this->createChunkedBody($stream));
        $request->getBody()->rewind();

        if ($codings === ['gzip'] || $codings === ['x-gzip']) {
            $request = StreamHelper::unzipBody($request, $this->maxInflatedBodySize);
            $request->getBody()->rewind();
            $codings = [];
        }

        $request = $codings === []
            ? $request->withoutHeader('Transfer-Encoding')
            : $request->withHeader('Transfer-Encoding', \implode(', ', $codings));

        return $request->withHeader('Content-Length', (string) $request->getBody()->getSize());
    }

    /**
     * The body ends with the stream if the stream ends before the last chunk.
     *
     * @throws InvalidRequest
     * @throws SizeLimitExceeded
     */
    private function createChunkedBody(StreamClient $stream): StreamInterface
    {
        $fileStream = StreamHelper::createFileStream();
        $decoder = new ChunkedDecoder($fileStream, $this->maxInflatedBodySize);
        $slice = new TimeSlice(checkEvery: 1);
        $waited = false;

        try {
            foreach ($stream->getIterator() as $chunk) {
                // The stream has suspended the fiber while it waited for the chunk
                $waited and $slice->reset();
                if ($decoder->write($chunk)) {
                    return $fileStream;
                }

                unset($chunk);
                $waited = !$stream->hasData();
                $waited or $slice->tick();
            }
        } catch (\Throwable $e) {
            $fileStream->close();
            throw $e;
        }

        return $fileStream;
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
        $slice = new TimeSlice(checkEvery: 1);
        $waited = false;

        // The loop checks the limit only after a chunk arrives
        if ($limit !== null && $limit <= 0) {
            return $fileStream;
        }

        foreach ($stream->getIterator() as $chunk) {
            // The stream has suspended the fiber while it waited for the chunk
            $waited and $slice->reset();
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
            $waited = !$stream->hasData();
            $waited or $slice->tick();
        }

        return $fileStream;
    }
}
