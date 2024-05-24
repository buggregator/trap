<?php

declare(strict_types=1);

namespace Buggregator\Trap\Handler\Http\Handler;

use Buggregator\Trap\Handler\Http\Emitter as HttpEmitter;
use Buggregator\Trap\Handler\Http\RequestHandler;
use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Support\Timer;
use Buggregator\Trap\Traffic\StreamClient;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Upgrade the connection to a websocket and send a simple timer to the client.
 *
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class Websocket implements RequestHandler
{
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
        /** @var mixed $time */
        $time = $request->getAttribute('begin_at');
        $time = $time instanceof \DateTimeImmutable ? $time : new \DateTimeImmutable();

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
        yield new Frame\Http($request, $time);

        HttpEmitter::emit($streamClient, $response);
        unset($response);

        yield from $this->handleWebsocket($streamClient, $time);
    }

    /**
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
            $response = \chr(129) . \chr(\strlen($content)) . $content;

            $stream->sendData($response);
        }

        return [];
    }
}
