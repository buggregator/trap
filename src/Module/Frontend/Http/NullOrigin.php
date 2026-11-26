<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Http;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A page without an origin: the sandboxed frame of an `html` event that runs the sender's scripts,
 * a `file://` or `data:` page. It reaches ingestion only, never the UI API or the WebSocket:
 * a simple POST needs no preflight, and a WebSocket has no CORS at all.
 *
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class NullOrigin
{
    public static function of(ServerRequestInterface $request): bool
    {
        return $request->getHeaderLine('Origin') === 'null';
    }

    public static function refuse(): ResponseInterface
    {
        return new Response(
            403,
            ['Content-Type' => 'application/json'],
            '{"code":"origin_null","error":"not available to pages without an origin (sandboxed frames, file://)"}',
        );
    }
}
