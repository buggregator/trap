<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Traffic\Dispatcher;

use Buggregator\Trap\Server\Handler\Http\Emitter as HttpEmitter;
use Buggregator\Trap\Server\Handler\Http\Handler\MiddlewareHandler;
use Buggregator\Trap\Server\Handler\Http\Middleware;
use Buggregator\Trap\Server\Handler\Http\RequestHandler;
use Buggregator\Trap\Server\Handler\Pipeline;
use Buggregator\Trap\Server\Proto\Frame;
use Buggregator\Trap\Server\Traffic\Dispatcher;
use Buggregator\Trap\Server\Traffic\Parser;
use Buggregator\Trap\Server\Traffic\StreamClient;
use Buggregator\Trap\Support\Stream\SizeLimitExceeded;
use Nyholm\Psr7\Response;

/**
 * @internal
 * @psalm-internal Buggregator\Trap
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
     * @param bool $silentMode Don't emit Frames on dispatch if set to true.
     * @param int $maxInflatedBodySize A compressed body inflating past it, in bytes, is answered with 413;
     *        values below 1 fall back to {@see Parser\Http::MAX_INFLATED_BODY_SIZE}.
     */
    public function __construct(
        iterable $middlewares = [],
        array $handlers = [],
        private readonly bool $silentMode = false,
        int $maxInflatedBodySize = Parser\Http::MAX_INFLATED_BODY_SIZE,
    ) {
        // Init HTTP parser.
        $this->parser = new Parser\Http($maxInflatedBodySize);

        $handlers[] = new MiddlewareHandler($middlewares);

        // Build pipeline of handlers.
        $this->pipeline = Pipeline::build(
            $handlers,
            /** @see RequestHandler::handle() */
            'handle',
            static function (): never {
                throw new \LogicException('No handler found for request.');
            },
            'never',
        );
    }

    /**
     * @throws \Exception
     */
    #[\Override]
    public function dispatch(StreamClient $stream): iterable
    {
        try {
            $request = $this->parser->parseStream($stream);
        } catch (SizeLimitExceeded) {
            // Answered before any handler: a compressed body must not reach them inflated past the limit
            HttpEmitter::emit($stream, new Response(413, body: 'The request body is too large.'));
            $stream->disconnect();
            return;
        }

        $generator = ($this->pipeline)(
            $stream,
            $request->withAttribute('begin_at', $stream->getCreatedAt()),
        );

        if ($this->silentMode) {
            foreach ($generator as $frame) {
                unset($frame);
            }
        } else {
            yield from $generator;
        }
    }

    #[\Override]
    public function detect(string $data, \DateTimeImmutable $createdAt): ?bool
    {
        if (!\str_contains($data, "\r\n")) {
            return null;
        }

        return \preg_match('/^(GET|POST|PUT|HEAD|OPTIONS|DELETE|PATCH|TRACE|CONNECT) \\S++ HTTP\\/1\\.\\d\\r$/m', $data) === 1;
    }
}
