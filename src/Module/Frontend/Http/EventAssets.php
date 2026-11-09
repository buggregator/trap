<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Http;

use Buggregator\Trap\Handler\Http\Middleware;
use Buggregator\Trap\Handler\Router\Method;
use Buggregator\Trap\Handler\Router\Router as CommonRouter;
use Buggregator\Trap\Module\Frontend\UiControllers;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class EventAssets implements Middleware
{
    private readonly CommonRouter $router;

    public function __construct(UiControllers $controllers)
    {
        $this->router = CommonRouter::compose(...$controllers->assets());
    }

    #[\Override]
    public function handle(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        $method = $request->getMethod();

        $handler = $this->router->match(Method::fromString($method), (string) $request->getUri());

        if ($handler === null) {
            return $next($request);
        }

        /** @var ResponseInterface $response */
        $response = $handler() ?? new Response(404);

        return $response;
    }
}
