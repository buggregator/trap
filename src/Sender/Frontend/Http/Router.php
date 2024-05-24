<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Frontend\Http;

use Buggregator\Trap\Handler\Http\Middleware;
use Buggregator\Trap\Handler\Router\Method;
use Buggregator\Trap\Handler\Router\Router as CommonRouter;
use Buggregator\Trap\Logger;
use Buggregator\Trap\Sender\Frontend\EventStorage;
use Buggregator\Trap\Sender\Frontend\Service;
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
    private readonly CommonRouter $router;

    public function __construct(
        private readonly Logger $logger,
        EventStorage $eventsStorage,
    ) {
        $service = new Service($logger, $eventsStorage);
        $this->router = CommonRouter::new($service);
    }

    public function handle(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        try {
            $path = \trim($request->getUri()->getPath(), '/');
            $method = Method::fromString($request->getMethod());

            $handler = $this->router->match($method, $path);

            if ($handler === null) {
                return new Response(404);
            }

            try {
                // Params
                if ($method === Method::Get) {
                    $params = $request->getQueryParams();
                } else {
                    /** @var mixed $params */
                    $params = Json::decode((string) $request->getBody());
                    \is_array($params) or $params = [];
                }
            } catch (\Throwable) {
                $params = [];
            }

            /** @var mixed $message */
            $message = $handler(...$params);

            return new Response(
                200,
                [
                    'Content-Type' => 'application/json',
                    'Cache-Control' => 'no-cache',
                ],
                Json::encode($message),
            );
        } catch (\Throwable $e) {
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
