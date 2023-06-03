<?php

declare(strict_types=1);

namespace Buggregator\Client\Handler\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Http middleware interface.
 *
 * @internal
 * @psalm-internal Buggregator\Client
 */
interface Middleware
{
    /**
     * @param callable(ServerRequestInterface): ResponseInterface $next
     */
    public function handle(ServerRequestInterface $request, callable $next): ResponseInterface;
}
