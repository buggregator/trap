<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Http;

use Buggregator\Trap\Server\Handler\Http\Middleware;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class Cors implements Middleware
{
    /**
     * Paths only the UI of the same origin may use: the debugger reads files and runs code in the debugged
     * script, so another page in the browser must neither read its answers nor pass a preflight.
     */
    private const SAME_ORIGIN_PATHS = ['/api/xdebug'];

    #[\Override]
    public function handle(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        if (self::sameOriginOnly($request->getUri()->getPath())) {
            return $request->getMethod() === 'OPTIONS' ? new Response(403) : $next($request);
        }

        $response = $request->getMethod() === 'OPTIONS'
            ? new Response(200)
            : $next($request);

        return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Headers', '*')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
    }

    private static function sameOriginOnly(string $path): bool
    {
        foreach (self::SAME_ORIGIN_PATHS as $prefix) {
            if ($path === $prefix || \str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }
}
