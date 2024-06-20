<?php

declare(strict_types=1);

namespace Buggregator\Trap\Handler\Http\Handler;

use Buggregator\Trap\Handler\Http\Emitter as HttpEmitter;
use Buggregator\Trap\Handler\Http\Middleware;
use Buggregator\Trap\Handler\Http\RequestHandler;
use Buggregator\Trap\Handler\Pipeline;
use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Sender\Frontend\Http\Pipeline as FrontendPipeline;
use Buggregator\Trap\Traffic\StreamClient;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Simple fallback handler that runs {@see Pipeline} of {@see Middleware} and emits {@see ResponseInterface}.
 *
 * @internal
 * @psalm-internal Buggregator\Trap
 */
final class Fallback implements RequestHandler
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

    public function handle(StreamClient $streamClient, ServerRequestInterface $request, callable $next): iterable
    {
        /** @var mixed $time */
        $time = $request->getAttribute('begin_at');
        $time = $time instanceof \DateTimeImmutable ? $time : new \DateTimeImmutable();
        $gotFrame = false;

        try {
            $fiber = new \Fiber(($this->pipeline)(...));
            do {
                /** @var mixed $got */
                $got = $fiber->isStarted() ? $fiber->resume() : $fiber->start($request);

                if ($got instanceof Frame) {
                    $gotFrame = true;
                    yield $got;
                }

                if ($got instanceof ResponseInterface) {
                    HttpEmitter::emit($streamClient, $got);
                }

                if ($fiber->isTerminated()) {
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

        /** @var mixed $response */
        $response ??= null;
        if ($response instanceof ResponseInterface && $response->hasHeader(FrontendPipeline::FRONTEND_HEADER)) {
            return;
        }

        if (!$gotFrame) {
            yield new Frame\Http(
                $request,
                $time,
            );
        }
    }
}
