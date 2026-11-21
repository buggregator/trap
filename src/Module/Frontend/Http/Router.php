<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Http;

use Buggregator\Trap\Module\Frontend\BadRouteArgument;
use Buggregator\Trap\Module\Frontend\Message\Error;
use Buggregator\Trap\Module\Frontend\Message\HttpCode;
use Buggregator\Trap\Module\Frontend\UiControllers;
use Buggregator\Trap\Server\Handler\Http\Middleware;
use Buggregator\Trap\Server\Handler\Router\Method;
use Buggregator\Trap\Server\Handler\Router\Router as CommonRouter;
use Buggregator\Trap\Server\Info;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Support\Json;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class Router implements Middleware
{
    /**
     * The argument a controller method takes the HTTP request by: `?ServerRequestInterface $__request = null`.
     * Null over the WebSocket RPC, which has no request of its own.
     */
    public const REQUEST = '__request';

    private readonly CommonRouter $router;

    public function __construct(
        private readonly Logger $logger,
        UiControllers $controllers,
    ) {
        $this->router = CommonRouter::compose(...$controllers->api());
    }

    #[\Override]
    public function handle(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        try {
            $method = Method::fromString($request->getMethod());

            $handler = $this->router->match($method, (string) $request->getUri());

            if ($handler === null) {
                return $next($request);
            }

            // Params: the query, and the JSON body of a request other than GET
            $params = $request->getQueryParams();
            if ($method !== Method::Get) {
                try {
                    /** @var mixed $body */
                    $body = Json::decode((string) $request->getBody());
                    \is_array($body) and $params = [...$params, ...$body];
                } catch (\Throwable) {
                    // No JSON body
                }
            }

            // Last, so a body or query field of the name can't stand for it
            $params[self::REQUEST] = $request;

            /** @var mixed $message */
            $message = $handler(...\array_filter($params, \is_string(...), \ARRAY_FILTER_USE_KEY));

            return new Response(
                $message instanceof HttpCode ? $message->httpCode() : 200,
                [
                    'Content-Type' => 'application/json',
                    'Cache-Control' => 'no-cache',
                ],
                // A flame chart nests as deep as the recursion in the profiled code
                Json::encode($message, depth: 10_000),
            );
        } catch (\Throwable $e) {
            if (isset($method) && BadRouteArgument::isOf($e, $this->router->resolve($method, (string) $request->getUri()))) {
                return new Response(
                    400,
                    ['Content-Type' => 'application/json', 'Cache-Control' => 'no-cache'],
                    Json::encode(new Error($e->getMessage(), 400)),
                );
            }

            $this->logger->exception($e);

            return new Response(
                500,
                [
                    'Content-Type' => 'application/json',
                    'Cache-Control' => 'no-cache',
                ],
                Json::encode(['error' => $e->getMessage()]),
            );
        }
    }
}
