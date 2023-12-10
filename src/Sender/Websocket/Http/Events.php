<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender\Websocket\Http;

use Buggregator\Trap\Handler\Http\Middleware;
use Buggregator\Trap\Sender\Websocket\EventsStorage;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class Events implements Middleware
{
    public function __construct(
        private EventsStorage $framesStorage,
    ) {
    }

    public function handle(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if ($path !== '/api/events') {
            return $next($request);
        }

        return new Response(
            200,
            [
                'Content-Type' => 'application/json',
                'Cache-Control' => 'no-cache',
            ],
            \json_encode(
                $this->getEvents(),
                \JSON_THROW_ON_ERROR,
            ),
        );
    }

    private function getEvents()
    {
        return [
            'data' => \iterator_to_array($this->framesStorage, false),
            'meta' => [
                'grid' => []
            ]
        ];
    }
}
