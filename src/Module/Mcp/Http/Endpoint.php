<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp\Http;

use Buggregator\Trap\Module\Mcp\Caller;
use Buggregator\Trap\Module\Mcp\Config\Mcp as Config;
use Buggregator\Trap\Module\Mcp\Protocol\JsonRpc;
use Buggregator\Trap\Module\Mcp\Protocol\McpServer;
use Buggregator\Trap\Module\Mcp\Protocol\RpcError;
use Buggregator\Trap\Server\Handler\Http\Middleware;
use Buggregator\Trap\Support\Json;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The MCP Streamable HTTP transport at `/mcp`, stateless and without SSE: every request is answered
 * with a JSON response. Other paths go down the pipeline.
 *
 * With a token configured, a request without it gets `401` before any other check, so it learns
 * nothing of the server. A request of another path goes through the {@see TokenGuard}.
 *
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class Endpoint implements Middleware
{
    public const PATH = '/mcp';

    /** Hosts of the origins allowed to call the server from a browser. */
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    public function __construct(
        private readonly JsonRpc $rpc,
        private readonly Caller $caller,
        private readonly Config $config,
        private readonly TokenGuard $guard,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        if (!self::isMcpPath($request->getUri()->getPath())) {
            return $this->guard->handle($request, $next);
        }

        $bearer = self::bearer($request->getHeaderLine('Authorization'));
        if (($this->config->token ?? '') !== '' && !$this->isToken($bearer)) {
            return new Response(401, [
                'WWW-Authenticate' => $bearer === null ? 'Bearer' : 'Bearer error="invalid_token"',
                'Content-Type' => 'text/plain',
            ], 'Unauthorized.');
        }

        // DNS rebinding: a page of any site may resolve its own host name to 127.0.0.1
        if ($request->hasHeader('Origin') && !self::isLocalOrigin($request->getHeaderLine('Origin'))) {
            return new Response(403, ['Content-Type' => 'text/plain'], 'Forbidden origin.');
        }

        // No SSE stream to open with GET: the server never sends messages of its own
        if ($request->getMethod() !== 'POST') {
            return new Response(405, ['Allow' => 'POST']);
        }

        $version = $request->getHeaderLine('MCP-Protocol-Version');
        if ($version !== '' && !\in_array($version, McpServer::VERSIONS, true)) {
            return new Response(400, ['Content-Type' => 'text/plain'], "Unsupported MCP protocol version: $version.");
        }

        try {
            /** @var mixed $payload */
            $payload = Json::decode((string) $request->getBody());
        } catch (\JsonException $e) {
            return self::json(400, JsonRpc::error(null, RpcError::PARSE_ERROR, 'Parse error: ' . $e->getMessage()));
        }

        /** @var (\Closure(): bool)|null $disconnected */
        $disconnected = $request->getAttribute(Middleware::DISCONNECTED);
        $response = $disconnected instanceof \Closure
            ? $this->caller->connected($disconnected, fn(): ?array => $this->rpc->process($payload))
            : $this->rpc->process($payload);

        return match (true) {
            $response === null => new Response(202),
            // Only a message that is not a request at all has no id to answer to
            \array_key_exists('error', $response) && $response['id'] === null => self::json(400, $response),
            default => self::json(200, $response),
        };
    }

    private static function isLocalOrigin(string $origin): bool
    {
        $host = \parse_url($origin, \PHP_URL_HOST);

        return \is_string($host) && \in_array(\strtolower($host), self::LOCAL_HOSTS, true);
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private static function json(int $status, array $body): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], Json::encode($body));
    }

    /**
     * The token of a `Bearer` header, null for another scheme or none. The scheme is case-insensitive
     * and may be followed by several spaces, as RFC 7235 has it.
     */
    private static function bearer(string $authorization): ?string
    {
        return \preg_match('/^Bearer +(.+)$/i', $authorization, $matches) === 1 ? $matches[1] : null;
    }

    /**
     * Repeated slashes, a trailing one and the case don't matter.
     */
    private static function isMcpPath(string $path): bool
    {
        return \strtolower(\rtrim((string) \preg_replace('#/{2,}#', '/', $path), '/')) === self::PATH;
    }

    /**
     * Whether the configured token is set and is the given one.
     */
    private function isToken(?string $bearer): bool
    {
        $token = $this->config->token;

        return $token !== null && $token !== '' && $bearer !== null && \hash_equals($token, $bearer);
    }
}
