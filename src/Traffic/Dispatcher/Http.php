<?php

declare(strict_types=1);

namespace Buggregator\Client\Traffic\Dispatcher;

use Buggregator\Client\Handler\Http\Middleware;
use Buggregator\Client\Handler\Pipeline;
use Buggregator\Client\Proto\Frame;
use Buggregator\Client\Traffic\Dispatcher;
use Buggregator\Client\Traffic\Emitter;
use Buggregator\Client\Traffic\Parser;
use Buggregator\Client\Traffic\StreamClient;
use Psr\Http\Message\ResponseInterface;

/**
 * @internal
 * @psalm-internal Buggregator\Client
 */
final class Http implements Dispatcher
{
    private readonly Parser\Http $parser;
    /** @var Pipeline<Middleware, ResponseInterface> */
    private readonly Pipeline $pipeline;

    /**
     * @param iterable<array-key, Middleware> $middlewares
     */
    public function __construct(
        iterable $middlewares = [],
    ) {
        $this->parser = new Parser\Http();
        $this->pipeline = Pipeline::build(
            $middlewares,
            /** @see Middleware::handle() */
            'handle',
            static fn (): ResponseInterface => new \Nyholm\Psr7\Response(404),
            ResponseInterface::class,
        );
    }

    public function dispatch(StreamClient $stream): iterable
    {
        $time = new \DateTimeImmutable();
        $request = $this->parser->parseStream($stream);

        $response = ($this->pipeline)($request);

        Emitter\Http::emit($stream, $response);

        $stream->disconnect();

        yield new Frame\Http(
            $request,
            $time,
        );
    }

    public function detect(string $data): ?bool
    {
        if (!\str_contains($data, "\r\n")) {
            return null;
        }

        if (\preg_match('/^(GET|POST|PUT|HEAD|OPTIONS) \\S++ HTTP\\/1\\.\\d\\r$/m', $data) === 1) {
            return true;
        }

        return false;
    }
}
