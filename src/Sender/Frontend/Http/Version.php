<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Frontend\Http;

use Buggregator\Trap\Handler\Http\Middleware;
use Buggregator\Trap\Info;
use Buggregator\Trap\Support\Json;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class Version implements Middleware
{
    public function handle(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if ($path !== '/api/version') {
            return $next($request);
        }

        return new Response(
            200,
            [
                'Content-Type' => 'application/json',
                'Cache-Control' => 'no-cache',
            ],
            Json::encode(
                [
                    'version' => Info::VERSION,
                ],
            ),
        );
    }
}
