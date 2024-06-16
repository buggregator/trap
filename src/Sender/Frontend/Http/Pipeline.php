<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Frontend\Http;

use Buggregator\Trap\Handler\Http\Middleware;
use Buggregator\Trap\Handler\Pipeline as MiddlewaresPipeline;
use Buggregator\Trap\Info;
use Buggregator\Trap\Logger;
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
        Logger $logger,
        \Buggregator\Trap\Sender\FrontendSender $wsSender,
    ) {
        // Build pipeline of handlers.
        /** @var MiddlewaresPipeline<Middleware, ResponseInterface> */
        $this->pipeline = MiddlewaresPipeline::build(
            [
                new Cors(),
                new StaticFiles(),
                new EventAssets($logger, $wsSender->getEventStorage()),
                new Router($logger, $wsSender->getEventStorage()),
            ],
            /** @see Middleware::handle() */
            'handle',
            static fn(): ResponseInterface => new Response(404),
            ResponseInterface::class,
        );
    }

    public function handle(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        /** @var ResponseInterface $response */
        $response = ($this->pipeline)($request);

        return $response->getStatusCode() === 404
            ? $next($request)
            : $response->withHeader(self::FRONTEND_HEADER, Info::version());
    }
}
