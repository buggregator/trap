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

    /**
     * Marks the response of the pipeline end: no UI route took the request.
     * A UI route may answer 404 by itself, as for an unknown event.
     */
    private const UNHANDLED_HEADER = 'X-Trap-Frontend-Unhandled';

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
            static fn(): ResponseInterface => new Response(404, [self::UNHANDLED_HEADER => '1']),
            ResponseInterface::class,
        );
    }

    #[\Override]
    public function handle(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        /** @var ResponseInterface $response */
        $response = ($this->pipeline)($request);

        if ($response->hasHeader(self::UNHANDLED_HEADER)) {
            if (!self::isUiApiCall($request)) {
                return $next($request);
            }

            // An endpoint the Buggregator server has and Trap has not
            $response = new Response(404, ['Content-Type' => 'application/json'], '{"message":"Not found","code":404}');
        }

        return $response->withHeader(self::FRONTEND_HEADER, Info::version());
    }

    /**
     * A call of the UI API from a page of this origin: only the UI has pages here.
     * Not an HTTP dump, even when no route takes it.
     */
    private static function isUiApiCall(ServerRequestInterface $request): bool
    {
        return \str_starts_with($request->getUri()->getPath(), '/api/')
            && $request->getHeaderLine('Sec-Fetch-Site') === 'same-origin';
    }
}
