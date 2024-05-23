<?php

declare(strict_types=1);

namespace Buggregator\Trap\Handler\Http;

use Buggregator\Trap\Support\StreamHelper;
use Buggregator\Trap\Traffic\StreamClient;
use Fiber;
use Psr\Http\Message\ResponseInterface;

/**
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class Emitter
{
    /**
     * Preferred chunk size to be read from the stream before emitting. A value of 0 disables stream response.
     * Value greater than 100 KB might not work with Linux Docker.
     */
    public static int $bufferSize = 1024 * 100;

    /**
     * Send {@see ResponseInterface} to the client.
     *
     * Note: response stream might have concurrent readers. It will be wrapped automatically
     * using {@see StreamHelper::concurrentReadStream()} if it's possible, but if it's not, the
     * stream mustn't be read concurrently (in another fiber) or it will be corrupted.
     */
    public static function emit(StreamClient $streamClient, ResponseInterface $response): bool
    {
        // Send headers block
        $headerLines = [
            self::prepareStatusLine($response),
            ...self::prepareHeaders($response),
        ];
        $streamClient->sendData(\implode("\r\n", $headerLines) . "\r\n\r\n");

        self::emitBody($streamClient, $response);

        return true;
    }

    private static function prepareStatusLine(ResponseInterface $response): string
    {
        $reasonPhrase = $response->getReasonPhrase();

        if ($response->getStatusCode() === 103) {
            $reasonPhrase = 'Early Hints';
        }

        return \sprintf(
            'HTTP/%s %d%s',
            $response->getProtocolVersion(),
            $response->getStatusCode(),
            ($reasonPhrase ? ' ' . $reasonPhrase : ''),
        );
    }

    /**
     * @return iterable<array-key, non-empty-string>
     */
    private static function prepareHeaders(ResponseInterface $response): iterable
    {
        $headers = $response->getHeaders();
        if (!$response->hasHeader('Content-Length') && $response->getStatusCode() >= 200) {
            if ($response->getBody()->getSize() !== null) {
                $headers['Content-Length'] = [(string) $response->getBody()->getSize()];
            } else {
                $headers['Transfer-Encoding'] = ['chunked'];
            }
        }

        foreach ($headers as $header => $values) {
            $name = \ucwords((string) $header, '-');

            foreach ($values as $value) {
                yield \sprintf(
                    '%s: %s',
                    $name,
                    $value,
                );
            }
        }
    }

    private static function emitBody(StreamClient $streamClient, ResponseInterface $response): void
    {
        $chunked = !$response->hasHeader('Content-Length') && $response->getBody()->getSize() === null;

        try {
            $body = StreamHelper::concurrentReadStream($response->getBody());
        } catch (\Throwable) {
            $body = $response->getBody();
        }

        // Rewind stream if it's seekable.
        $body->isSeekable() and $body->rewind();

        if (!$body->isReadable()) {
            return;
        }

        while (!$body->eof()) {
            \assert(self::$bufferSize > 0);
            $string = $body->read(self::$bufferSize);
            if ($chunked) {
                $string = \sprintf("%x\r\n%s\r\n", \strlen($string), $string);
            }

            $streamClient->sendData($string);

            unset($string);
            \Fiber::suspend();
        }

        if ($chunked) {
            $streamClient->sendData("0\r\n\r\n");
        }
    }
}
