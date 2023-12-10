<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Websocket\Http;

use Buggregator\Trap\Handler\Http\Middleware;
use Buggregator\Trap\Info;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class StaticFiles implements Middleware
{
    public function handle(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if ($path === '/') {
            $path = '/index.html';
        }

        if (\preg_match('#^/((?:[a-zA-Z0-9\\-_]+/)?[a-zA-Z0-9.\\-\\[\\]() _]+?\\.([a-zA-Z0-4]++))$#', $path, $matches)) {
            $file = \sprintf("%s/resources/frontend/%s", Info::TRAP_ROOT, $matches[1]);

            if (!\is_file($file)) {
                return new Response(404);
            }

            $type = match($matches[2]) {
                'css' => 'text/css',
                'html' => 'text/html',
                'txt' => 'text/plain',
                'js' => 'application/javascript',
                'ico' => 'image/x-icon',
                'png' => 'image/png',
                'svg' => 'image/svg+xml',
                'xml' => 'application/xml',
                'webmanifest' => 'application/manifest+json',
                default => 'octet/stream',
            };

            return new Response(
                200,
                [
                    'Content-Type' => $type,
                    'Content-Length' => \filesize($file),
                ],
                \file_get_contents($file),
            );
        }

        return $next($request);
    }
}
