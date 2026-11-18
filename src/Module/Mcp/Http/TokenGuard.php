<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp\Http;

use Buggregator\Trap\Module\Mcp\Config\Mcp as Config;
use Buggregator\Trap\Server\Handler\Http\Middleware;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Answers `404` to a request carrying the MCP token, so that no module down the pipeline keeps, prints
 * or dumps it. A request the MCP endpoint serves never gets here: the endpoint goes before it on its port.
 *
 * The token is looked for anywhere in the request target, raw and decoded, and in every header value,
 * not in the body. Without a token configured, every request goes on.
 *
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class TokenGuard implements Middleware
{
    public function __construct(
        private readonly Config $config,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        return $this->carriesToken($request)
            ? new Response(404, ['Content-Type' => 'text/plain'], 'Not found.')
            : $next($request);
    }

    private function carriesToken(ServerRequestInterface $request): bool
    {
        $token = $this->config->token;
        if ($token === null || $token === '') {
            return false;
        }

        // The URI keeps the user info of an absolute-form target, which the request target drops
        $targets = [(string) $request->getUri(), $request->getRequestTarget()];
        foreach ($targets as $target) {
            foreach ([$target, \rawurldecode($target), \urldecode($target)] as $value) {
                if (\str_contains($value, $token)) {
                    return true;
                }
            }
        }

        foreach ($request->getHeaders() as $name => $values) {
            foreach ([(string) $name, ...$values] as $value) {
                if (\str_contains($value, $token)) {
                    return true;
                }
            }
        }

        return false;
    }
}
