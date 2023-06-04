<?php

declare(strict_types=1);

namespace Buggregator\Client\Handler\Http\Handler;

use Buggregator\Client\Handler\Http\Emitter as HttpEmitter;
use Buggregator\Client\Handler\Http\Middleware;
use Buggregator\Client\Handler\Http\RequestHandler;
use Buggregator\Client\Support\Timer;
use Buggregator\Client\Proto\Frame;
use Buggregator\Client\Traffic\StreamClient;
use DateTimeInterface;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @internal
 * @psalm-internal Buggregator\Client
 */
final class Websocket implements RequestHandler
{
    /**
     * @param iterable<array-key, Middleware> $middlewares
     */
    public function __construct(
    ) {
    }

    public function handle(StreamClient $streamClient, ServerRequestInterface $request, callable $next): iterable
    {
        if (
            !$request->hasHeader('Sec-WebSocket-Key')
            || \preg_match('/\\bwebsocket\\b/i', $request->getHeaderLine('Upgrade')) !== 1
        ) {
            yield from $next($streamClient, $request);
            return;
        }

        // Get the time of the request
        $time = $request->getAttribute('begin_at', null);
        $time = $time instanceof DateTimeInterface ? $time : new \DateTimeImmutable();

        // Calculate the accept key for the handshake
        $key = $request->getHeaderLine('Sec-WebSocket-Key');
        $accept = \base64_encode(\sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

        // Prepare the response for the handshake
        $response = new Response(101, [
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Version' => '13',
            'Sec-WebSocket-Accept' => $accept,
        ]);

        // Send the Request Frame to the Buffer
        yield new Frame\Http(
            $request,
            $time,
        );

        HttpEmitter::emit($streamClient, $response);
        unset($response);

        yield from $this->handleWebsocket($streamClient, $time);
    }

    /**
     * Todo: extract to a separate Websocket service
     *
     * @return iterable<array-key, Frame>
     */
    private function handleWebsocket(
        StreamClient $stream,
        \DateTimeImmutable $time,
    ): iterable {
        $timer = new Timer(1.0);
        while ($timer->wait()) {
            $timer->reset();

            $content = 'Elapsed: ' . (\time() - $time->getTimestamp()) . 's';
            $response = chr(129) . chr(strlen($content)) . $content;

            $stream->sendData($response);
        }

        return [];
    }
}
