<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Handler\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Http middleware interface.
 *
 * @internal
 * @psalm-internal Buggregator\Trap
 */
interface Middleware
{
    /**
     * The request attribute with a `\Closure(): bool` that tells whether the client has disconnected,
     * for a middleware that keeps the request waiting: then nobody reads its response.
     */
    public const DISCONNECTED = 'trap.disconnected';

    /**
     * @param callable(ServerRequestInterface): ResponseInterface $next
     */
    public function handle(ServerRequestInterface $request, callable $next): ResponseInterface;
}
