<?php

declare(strict_types=1);

namespace Buggregator\Trap\Handler\Http\Middleware;

use Buggregator\Trap\Handler\Http\Middleware;
use Buggregator\Trap\Info;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class Resources implements Middleware
{
    public function handle(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        // Shortcut only for favicon
        if ($path === '/favicon.ico') {
            $path = '/resources/favicon.ico';
        }

        if (\preg_match('#^/resources/([a-zA-Z0-9.\\-\\[\\]() _]+?\\.([a-zA-Z0-4]++))$#', $path, $matches)) {
            $file = \sprintf("%s/resources/public/%s", Info::TRAP_ROOT, $matches[1]);

            if (!\is_file($file)) {
                return new Response(404);
            }

            $type = match($matches[2]) {
                'css' => 'text/css',
                'js' => 'application/javascript',
                'ico' => 'image/x-icon',
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
