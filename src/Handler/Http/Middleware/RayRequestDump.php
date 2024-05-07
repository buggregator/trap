<?php

declare(strict_types=1);

namespace Buggregator\Trap\Handler\Http\Middleware;

use Buggregator\Trap\Handler\Http\Middleware;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class RayRequestDump implements Middleware
{
    public function handle(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        if (\str_ends_with($request->getUri()->getPath(), '_availability_check')) {
            return new Response(400);
        }

        return $next($request);
    }
}
