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
}
