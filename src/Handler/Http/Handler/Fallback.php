<?php

declare(strict_types=1);

namespace Buggregator\Client\Handler\Http\Handler;

use Buggregator\Client\Handler\Http\Emitter as HttpEmitter;
use Buggregator\Client\Handler\Http\Middleware;
use Buggregator\Client\Handler\Http\RequestHandler;
use Buggregator\Client\Handler\Pipeline;
use Buggregator\Client\Proto\Frame;
use Buggregator\Client\Traffic\StreamClient;
use DateTimeInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Simple fallback handler that runs {@see Pipeline} of {@see Middleware} and emits {@see ResponseInterface}.
 *
 * @internal
 * @psalm-internal Buggregator\Client
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
            static fn (): ResponseInterface => new \Nyholm\Psr7\Response(404),
            ResponseInterface::class,
        );
    }

    public function handle(StreamClient $streamClient, ServerRequestInterface $request, callable $next): iterable
    {
        $time = $request->getAttribute('begin_at', null);
        $time = $time instanceof DateTimeInterface ? $time : new \DateTimeImmutable();

        $response = ($this->pipeline)($request);
        HttpEmitter::emit($streamClient, $response);

        $streamClient->disconnect();

        yield new Frame\Http(
            $request,
            $time,
        );
    }
}
