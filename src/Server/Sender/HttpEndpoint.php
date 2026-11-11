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
    /**
     * @param int<1, 65535> $port
     * @param non-empty-string $host
     * @param list<Middleware> $middlewares
     * @param list<RequestHandler> $handlers
     */
    public function __construct(
        public readonly int $port,
        public readonly string $host,
        public readonly array $middlewares,
        public readonly array $handlers,
    ) {}
}
