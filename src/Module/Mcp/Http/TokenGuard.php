<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp\Http;

use Buggregator\Trap\Module\Mcp\Config\Mcp as Config;
use Buggregator\Trap\Server\Handler\Http\Middleware;
use Buggregator\Trap\Server\Logger;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Answers `404` to a request carrying the MCP token, so that no module down the pipeline keeps, prints
 * or dumps it. A request the MCP endpoint serves never gets here: the endpoint goes before it on its port.
 *
 * The token is looked for anywhere in the request target, raw and decoded, and in every header name
 * and value, not in the body. Without a token configured, every request goes on.
 *
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class TokenGuard implements Middleware
{
    /** A shorter token is likely to turn up in ordinary requests by chance */
    public const MIN_TOKEN_LENGTH = 16;

    private bool $rejected = false;

    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
        $token = $this->config->token ?? '';
        $token !== '' && \strlen($token) < self::MIN_TOKEN_LENGTH and $this->logger->warning(
            'MCP: the token is shorter than %d characters. Any request that happens to contain it, in its '
            . 'target or a header, is answered 404 and dropped. Set a longer TRAP_MCP_TOKEN.',
            self::MIN_TOKEN_LENGTH,
        );
    }

    #[\Override]
    public function handle(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        $token = $this->config->token ?? '';
        if ($token === '' || !self::carriesToken($request, $token)) {
            return $next($request);
        }

        $this->logRejected($request, $token);

        return new Response(404, ['Content-Type' => 'text/plain'], 'Not found.');
    }

    /**
     * @param non-empty-string $token
     */
    private static function carriesToken(ServerRequestInterface $request, string $token): bool
    {
        // The URI keeps the user info of an absolute-form target, which the request target drops
        foreach ([(string) $request->getUri(), $request->getRequestTarget()] as $target) {
            if (self::contains($target, $token)) {
                return true;
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

    /**
     * Whether the value carries the token as it is or percent-decoded.
     *
     * @param non-empty-string $token
     */
    private static function contains(string $value, string $token): bool
    {
        foreach ([$value, \rawurldecode($value), \urldecode($value)] as $variant) {
            if (\str_contains($variant, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Masks the token as it is, not percent-encoded.
     *
     * @param non-empty-string $token
     */
    private static function redact(string $value, string $token): string
    {
        $value = \str_replace($token, '***', $value);
        // A token of a character or two may be in the mask or come out of joining what is left
        while (\str_contains($value, $token)) {
            $value = \str_replace($token, '', $value);
        }

        return $value;
    }

    /**
     * Whether a mask may sit next to a part of the token: the first character of the token right after it or
     * the last one right before it. A periodic token like `abcabc` leaves `***abc` of `abcabcabc`.
     *
     * @param non-empty-string $token
     */
    private static function touchesMask(string $line, string $token): bool
    {
        $parts = \explode('***', $line);
        for ($i = 1, $count = \count($parts); $i < $count; ++$i) {
            if (\str_ends_with($parts[$i - 1], $token[-1]) || \str_starts_with($parts[$i], $token[0])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The first rejection of a run is a warning, so that a token turning up in ordinary traffic is noticed;
     * the next ones are debug lines.
     *
     * @param non-empty-string $token
     */
    private function logRejected(ServerRequestInterface $request, string $token): void
    {
        $line = self::redact($request->getMethod() . ' ' . $request->getUri()->getPath(), $token);
        // A percent-encoded token is left by the replace
        self::contains($line, $token) || self::touchesMask($line, $token)
            and $line = self::redact($request->getMethod() . ' (path withheld)', $token);

        if ($this->rejected) {
            $this->logger->debug('MCP: %s carries the MCP token, answered 404.', $line);
            return;
        }

        $this->rejected = true;
        $this->logger->warning(
            'MCP: %s carries the MCP token, answered 404. The next such requests are logged with -vv.',
            $line,
        );
    }
}
