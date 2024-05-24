<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Frontend\Http;

use Buggregator\Trap\Handler\Http\Emitter as HttpEmitter;
use Buggregator\Trap\Handler\Http\RequestHandler as RequestHandlernterace;
use Buggregator\Trap\Sender;
use Buggregator\Trap\Traffic\StreamClient;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Read about Sec-WebSocket-Extensions:
 * @link https://datatracker.ietf.org/doc/html/rfc7692
 * @link https://www.igvita.com/2013/11/27/configuring-and-optimizing-websocket-compression/
 *
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class RequestHandler implements RequestHandlernterace
{
    public function __construct(
        private readonly Sender\Frontend\ConnectionPool $connectionPool,
    ) {}

    public function handle(StreamClient $streamClient, ServerRequestInterface $request, callable $next): \Generator
    {
        if (
            !$request->hasHeader('Sec-WebSocket-Key')
            || \preg_match('/\\bwebsocket\\b/i', $request->getHeaderLine('Upgrade')) !== 1
        ) {
            yield from $next($streamClient, $request);
            return;
        }

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

        HttpEmitter::emit($streamClient, $response);
        unset($response);

        $this->connectionPool->addStream($streamClient);
    }
}
