<?php

declare(strict_types=1);

namespace Buggregator\Trap\Traffic\Dispatcher;

use Buggregator\Trap\Handler\Http\Handler\Fallback;
use Buggregator\Trap\Handler\Http\Middleware;
use Buggregator\Trap\Handler\Http\RequestHandler;
use Buggregator\Trap\Handler\Pipeline;
use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Traffic\Dispatcher;
use Buggregator\Trap\Traffic\Parser;
use Buggregator\Trap\Traffic\StreamClient;

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
     */
    public function __construct(
        iterable $middlewares = [],
        array $handlers = [],
        private readonly bool $silentMode = false,
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
            'never',
        );
    }

    /**
     * @throws \Exception
     */
    public function dispatch(StreamClient $stream): iterable
    {
        $generator = ($this->pipeline)(
            $stream,
            $this->parser
                ->parseStream($stream)
                ->withAttribute('begin_at', $stream->getCreatedAt()),
        );

        if ($this->silentMode) {
            foreach ($generator as $frame) {
                unset($frame);
            }
        } else {
            yield from $generator;
        }
    }

    public function detect(string $data, \DateTimeImmutable $createdAt): ?bool
    {
        if (!\str_contains($data, "\r\n")) {
            return null;
        }

        return \preg_match('/^(GET|POST|PUT|HEAD|OPTIONS|DELETE|PATCH|TRACE|CONNECT) \\S++ HTTP\\/1\\.\\d\\r$/m', $data) === 1;
    }
}
