<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Websocket;

use Buggregator\Trap\Handler\Router\Attribute\RegexpRoute;
use Buggregator\Trap\Handler\Router\Attribute\StaticRoute;
use Buggregator\Trap\Handler\Router\Method;
use Buggregator\Trap\Handler\Router\Router;
use Buggregator\Trap\Logger;
use Buggregator\Trap\Support\Uuid;
use JsonSerializable;

/**
 * @internal
 */
final class RPC
{
    private readonly Router $router;

    public function __construct(
        private readonly Logger $logger,
        private readonly EventsStorage $eventsStorage,
    ) {
        $this->router = Router::new($this);
    }

    public function handleMessage(string $message): ?object
    {
        try {
            if ($message === '') {
                return (object)[];
            }

            $json = \json_decode($message, true, 512, \JSON_THROW_ON_ERROR);
            if (!\is_array($json)) {
                return null;
            }
            $id = $json['id'] ?? 1;

            if (isset($json['connect'])) {
                return new RPC\Connected(id: $id, client: Uuid::uuid4());
            }

            if (isset($json['rpc']['method'])) {
                $method = $json['rpc']['method'];

                return $this->callMethod($id, $method);
            }
        } catch (\Throwable $e) {
            $this->logger->exception($e);
        }
        return null;
    }

    #[RegexpRoute(Method::Delete, '#^/api/events/(?<uuid>[a-f0-9-]++)#i')]
    public function eventDelete(string $uuid): bool
    {
        $this->eventsStorage->delete($uuid);
        return true;
    }

    #[StaticRoute(Method::Delete, 'api/events')]
    public function eventsDelete(): bool
    {
        $this->eventsStorage->clear();
        return true;
    }

    private function callMethod(int|string $id, string $initMethod): ?JsonSerializable
    {
        [$method, $path] = \explode(':', $initMethod, 2);

        $route = $this->router->match(Method::fromString($method), $path ?? '');

        if ($route === null) {
            // todo: Error message
            return null;
        }
        $result = $route(id: $id);

        return $result === true
            ? new RPC\Success(id: $id, code: 200, status: true)
            : $result;
    }
}
