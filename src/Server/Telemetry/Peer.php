<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Telemetry;

/**
 * What a span calls: a database, an HTTP host, a cache, a queue; read from the span op and attributes
 * the way buggregator/server classifies spans.
 *
 * @internal
 */
final class Peer
{
    /**
     * @param 'db'|'http'|'cache'|'queue'|'unknown' $type
     */
    public function __construct(
        public readonly string $type = 'unknown',
        public readonly ?string $address = null,
    ) {}

    /**
     * @param array<array-key, mixed> $attributes Span data or OTel attributes; only scalar values are read.
     */
    public static function classify(string $op, array $attributes, string $description = ''): self
    {
        $attr = static fn(string ...$keys): ?string => self::first($attributes, ...$keys);

        return match (true) {
            \str_starts_with($op, 'db') && ($op === 'db' || \str_starts_with($op, 'db.')) => new self(
                'db',
                $attr('server.address', 'db.server.address', 'db.name', 'db.namespace'),
            ),
            $op === 'http.client' => new self('http', self::httpHost($attributes, $description)),
            \str_starts_with($op, 'cache.') => new self('cache', $attr('server.address')),
            \str_starts_with($op, 'queue') && ($op === 'queue' || \str_starts_with($op, 'queue.')) => new self(
                'queue',
                $attr('messaging.destination', 'messaging.destination.name'),
            ),
            default => new self(),
        };
    }

    /**
     * The host an outgoing HTTP call went to: from a URL attribute, else `server.address[:server.port]`,
     * else from a URL in the description, as in `POST http://checkout-api/orders`.
     */
    private static function httpHost(array $attributes, string $description): ?string
    {
        foreach (['http.url', 'url.full', 'url'] as $key) {
            $host = self::urlHost(self::first($attributes, $key));
            if ($host !== null) {
                return $host;
            }
        }

        $host = self::first($attributes, 'server.address');
        if ($host !== null) {
            $port = self::first($attributes, 'server.port');
            return $port === null ? $host : "$host:$port";
        }

        foreach (\preg_split('/\s+/', $description) ?: [] as $word) {
            $host = self::urlHost($word);
            if ($host !== null) {
                return $host;
            }
        }

        return null;
    }

    private static function urlHost(?string $url): ?string
    {
        if ($url === null || !\str_contains($url, '://')) {
            return null;
        }

        $parts = \parse_url($url);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return isset($parts['port']) ? "{$parts['host']}:{$parts['port']}" : $parts['host'];
    }

    /**
     * @return non-empty-string|null The first of the keys with a non-empty scalar value, as a string.
     */
    private static function first(array $attributes, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            /** @var mixed $value */
            $value = $attributes[$key] ?? null;
            if (\is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            if (\is_scalar($value) && ($value = (string) $value) !== '') {
                return $value;
            }
        }

        return null;
    }
}
