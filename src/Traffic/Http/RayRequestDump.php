<?php

declare(strict_types=1);

namespace Buggregator\Client\Traffic\Http;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RayRequestDump implements HandlerInterface
{
    public function priority(): int
    {
        return 0;
    }

    public function handle(ServerRequestInterface $request, \Closure $next): ResponseInterface
    {
        if (\str_ends_with($request->getUri()->getPath(), '_availability_check')) {
            return new Response(400);
        }

        return $next($request);
    }
}
