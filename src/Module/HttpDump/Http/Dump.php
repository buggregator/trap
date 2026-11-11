<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\HttpDump\Http;

use Buggregator\Trap\Module\HttpDump\Frame;
use Buggregator\Trap\Server\Handler\Http\Middleware;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Dumps the request that reached the end of the pipeline: no module answered it.
 *
 * @internal
 */
final class Dump implements Middleware
{
    #[\Override]
    public function handle(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        /** @var mixed $time */
        $time = $request->getAttribute('begin_at');

        \Fiber::suspend(new Frame($request, $time instanceof \DateTimeImmutable ? $time : new \DateTimeImmutable()));

        return new Response(404);
    }
}
