<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Http;

use Buggregator\Trap\Server\Handler\Http\Middleware;
use Buggregator\Trap\Server\Handler\Pipeline as MiddlewaresPipeline;
use Buggregator\Trap\Server\Info;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Full Frontend HTTP pipeline as single middleware.
 *
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class Pipeline implements Middleware
{
    public const FRONTEND_HEADER = 'X-Trap-Frontend';

    /** @var MiddlewaresPipeline<Middleware, ResponseInterface> */
    private MiddlewaresPipeline $pipeline;

    public function __construct(
        EventAssets $assets,
        Router $router,
    ) {
        // Build pipeline of handlers.
        /** @var MiddlewaresPipeline<Middleware, ResponseInterface> */
        $this->pipeline = MiddlewaresPipeline::build(
            [
                new Cors(),
                new StaticFiles(),
                $assets,
                $router,
            ],
            /** @see Middleware::handle() */
            'handle',
            static fn(): ResponseInterface => new Response(404),
            ResponseInterface::class,
        );
    }

    #[\Override]
    public function handle(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        /** @var ResponseInterface $response */
        $response = ($this->pipeline)($request);

        return $response->getStatusCode() === 404
            ? $next($request)
            : $response->withHeader(self::FRONTEND_HEADER, Info::version());
    }
}
