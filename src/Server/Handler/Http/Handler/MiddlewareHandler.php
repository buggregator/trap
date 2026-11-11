<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Handler\Http\Handler;

use Buggregator\Trap\Server\Handler\Http\Emitter as HttpEmitter;
use Buggregator\Trap\Server\Handler\Http\Middleware;
use Buggregator\Trap\Server\Handler\Http\RequestHandler;
use Buggregator\Trap\Server\Handler\Pipeline;
use Buggregator\Trap\Server\Proto\Frame;
use Buggregator\Trap\Server\Traffic\StreamClient;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The last request handler: runs the {@see Pipeline} of {@see Middleware} and emits its response.
 *
 * A middleware passes frames out by suspending the fiber it runs in. A request no middleware
 * answers gets 404.
 *
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class MiddlewareHandler implements RequestHandler
{
    /** @var Pipeline<Middleware, ResponseInterface> */
    private readonly Pipeline $pipeline;

    /**
     * @param iterable<array-key, Middleware> $middlewares
     */
    public function __construct(
        iterable $middlewares = [],
    ) {
        $this->pipeline = Pipeline::build(
            $middlewares,
            /** @see Middleware::handle() */
            'handle',
            static fn(): ResponseInterface => new Response(404),
            ResponseInterface::class,
        );
    }

    #[\Override]
    public function handle(StreamClient $streamClient, ServerRequestInterface $request, callable $next): iterable
    {
        try {
            $fiber = new \Fiber(($this->pipeline)(...));
            do {
                /** @var mixed $got */
                $got = $fiber->isStarted() ? $fiber->resume() : $fiber->start($request);

                if ($got instanceof Frame) {
                    yield $got;
                }

                if ($got instanceof ResponseInterface) {
                    HttpEmitter::emit($streamClient, $got);
                }

                if ($fiber->isTerminated()) {
                    /** @var mixed $response */
                    $response = $fiber->getReturn();
                    if (!$response instanceof ResponseInterface) {
                        throw new \RuntimeException('Invalid response type.');
                    }

                    HttpEmitter::emit($streamClient, $response);
                    break;
                }

                \Fiber::suspend();
            } while (true);
        } catch (\Throwable) {
            // Emit error response
            HttpEmitter::emit($streamClient, new Response(500));
        } finally {
            $streamClient->disconnect();
        }
    }
}
