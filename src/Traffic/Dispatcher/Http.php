<?php

declare(strict_types=1);

namespace Buggregator\Client\Traffic\Dispatcher;

use Buggregator\Client\Handler\Http\Handler\Fallback;
use Buggregator\Client\Handler\Http\Middleware;
use Buggregator\Client\Handler\Http\RequestHandler;
use Buggregator\Client\Handler\Pipeline;
use Buggregator\Client\Proto\Frame;
use Buggregator\Client\Traffic\Dispatcher;
use Buggregator\Client\Traffic\Parser;
use Buggregator\Client\Traffic\StreamClient;
use DateTimeImmutable;
use Generator;

/**
 * @internal
 * @psalm-internal Buggregator\Client
 */
final class Http implements Dispatcher
{
    private readonly Parser\Http $parser;

    /**
     * Pipeline of {@see RequestHandler}.
     * @var Pipeline<RequestHandler, iterable<array-key, Frame>>
     */
    private readonly Pipeline $pipeline;

    /**
     * @param iterable<array-key, Middleware> $middlewares
     * @param array<array-key, RequestHandler> $handlers
     */
    public function __construct(
        iterable $middlewares = [],
        array $handlers = [],
    ) {
        // Init HTTP parser.
        $this->parser = new Parser\Http();

        // Add default fallback handler at the end of pipeline.
        $handlers[] = new Fallback($middlewares);

        // Build pipeline of handlers.
        $this->pipeline = Pipeline::build(
            $handlers,
            /** @see RequestHandler::handle() */
            'handle',
            static function (): never { throw new \LogicException('No handler found for request.'); },
            Generator::class,
        );
    }

    public function dispatch(StreamClient $stream): iterable
    {
        yield from ($this->pipeline)(
            $stream,
            $this->parser
                ->parseStream($stream)
                ->withAttribute('begin_at', $stream->getCreatedAt()),
        );
    }

    public function detect(string $data, DateTimeImmutable $createdAt): ?bool
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
