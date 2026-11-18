<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Sender;

use Buggregator\Trap\Server\Handler\Http\Middleware;
use Buggregator\Trap\Server\Handler\Http\RequestHandler;

/**
 * @internal
 */
final class HttpEndpoint
{
    /** The host of a port whose endpoints all leave the host unset. */
    public const DEFAULT_HOST = '127.0.0.1';

    /**
     * @param int<1, 65535> $port
     * @param non-empty-string|null $host Null when not set: the endpoint takes the host of the other
     *        endpoints on the port, or {@see DEFAULT_HOST}.
     * @param list<Middleware> $middlewares
     * @param list<RequestHandler> $handlers
     */
    public function __construct(
        public readonly int $port,
        public readonly ?string $host,
        public readonly array $middlewares,
        public readonly array $handlers,
    ) {}

    /**
     * Whether only the local machine reaches a port listening on the host: `localhost`, `127.0.0.0/8`, `::1`
     * or `::ffff:127.0.0.0/104`, in brackets or not.
     */
    public static function isLoopback(string $host): bool
    {
        $host = \strtolower(\trim($host, '[]'));
        if ($host === 'localhost' || $host === 'localhost.') {
            return true;
        }

        $ip = \filter_var($host, \FILTER_VALIDATE_IP) === false ? false : \inet_pton($host);

        return match (true) {
            $ip === false => false,
            \strlen($ip) === 4 => $ip[0] === "\x7f",
            default => $ip === \inet_pton('::1') || \str_starts_with($ip, \str_repeat("\0", 10) . "\xff\xff\x7f"),
        };
    }

    /**
     * @return non-empty-string The URL of the root of a port listening on the host, an IPv6 one in brackets.
     */
    public static function url(string $host, int $port): string
    {
        return \sprintf(
            'http://%s:%d',
            \str_contains($host, ':') && !\str_starts_with($host, '[') ? "[$host]" : $host,
            $port,
        );
    }
}
