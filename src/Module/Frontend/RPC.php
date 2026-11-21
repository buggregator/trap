<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend;

use Buggregator\Trap\Module\Frontend\Message\Error;
use Buggregator\Trap\Module\Frontend\Message\HttpCode;
use Buggregator\Trap\Server\Handler\Router\Method;
use Buggregator\Trap\Server\Handler\Router\Router;
use Buggregator\Trap\Server\Logger;

/**
 * Routes the RPC calls of the WebSocket clients, `{method: "delete:api/event/{uuid}", data}`, to the UI API.
 *
 * The reply data is `{code, data}`: the HTTP status and the body the same call over HTTP gets.
 * The UI takes a call for done only on a 2xx code.
 *
 * @internal
 */
final class RPC
{
    private readonly Router $router;

    public function __construct(
        private readonly Logger $logger,
        UiControllers $controllers,
    ) {
        $this->router = Router::compose(...$controllers->api());
    }

    /**
     * @param mixed $message Array is expected
     */
    public function handleMessage(mixed $message): ?Message\Rpc
    {
        if (!\is_array($message) || !\is_string($method = $message['method'] ?? null)) {
            return null;
        }

        $data = isset($message['data']) && \is_array($message['data']) ? $message['data'] : [];
        [$verb, $path] = \explode(':', $method, 2) + [1 => ''];
        $httpMethod = Method::tryFrom(\strtoupper($verb));

        try {
            /** @var mixed $result */
            $result = $httpMethod === null
                ? new Error(\sprintf('RPC method `%s` is not `{verb}:{path}`.', $method), 400)
                : $this->call($httpMethod, $path, $data);
        } catch (\Throwable $e) {
            $this->logger->exception($e);
            $result = new Error($e->getMessage(), 500);
        }

        return new Message\Rpc(data: [
            'code' => $result instanceof HttpCode ? $result->httpCode() : 200,
            'data' => $result,
        ]);
    }

    private function call(Method $method, string $path, array $data): mixed
    {
        $route = $this->router->match($method, $path);

        if ($route === null) {
            $this->logger->error('RPC method `%s:%s` not found.', \strtolower($method->value), $path);
            return Error::notFound(\sprintf('RPC method `%s:%s` not found.', \strtolower($method->value), $path));
        }

        return $route(...\array_filter($data, \is_string(...), \ARRAY_FILTER_USE_KEY));
    }
}
