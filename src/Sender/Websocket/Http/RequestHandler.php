<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Websocket\Http;

use Buggregator\Trap\Handler\Http\Emitter as HttpEmitter;
use Buggregator\Trap\Handler\Http\RequestHandler as RequestHandlernterace;
use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Sender;
use Buggregator\Trap\Traffic\StreamClient;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class RequestHandler implements RequestHandlernterace
{
    public function __construct(
        private readonly Sender\Websocket\ConnectionPool $connectionPool,
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

        $this->connectionPool->addStream($streamClient);
    }
}
