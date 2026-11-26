<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Http;

use Buggregator\Trap\Module\Frontend\UiControllers;
use Buggregator\Trap\Server\Handler\Http\Middleware;
use Buggregator\Trap\Server\Handler\Router\Method;
use Buggregator\Trap\Server\Handler\Router\Router as CommonRouter;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The files of the events: attachments, raw bytes. A script of a page without an origin is refused, as by the
 * UI API; a link or an image of such a page still opens them: a browser sends no `Origin` with their GET.
 *
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

        if (NullOrigin::of($request)) {
            return NullOrigin::refuse();
        }

        /** @var ResponseInterface $response */
        $response = $handler() ?? new Response(404);

        return $response;
    }
}
