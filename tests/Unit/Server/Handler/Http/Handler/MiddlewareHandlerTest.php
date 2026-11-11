<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Handler\Http\Handler;

use Buggregator\Trap\Server\Handler\Http\Handler\MiddlewareHandler;
use Buggregator\Trap\Server\Handler\Http\Middleware;
use Buggregator\Trap\Server\Proto\Frame;
use Buggregator\Trap\Server\Traffic\StreamClient;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Covers(MiddlewareHandler::class)]
#[Test]
final class MiddlewareHandlerTest
{
    private string $sent = '';

    #[BeforeTest]
    public function resetSent(): void
    {
        $this->sent = '';
    }

    public function testRequestNoMiddlewareAnswersGets404AndNoFrame(): void
    {
        $frames = $this->handle();

        Assert::same($frames, []);
        Assert::string($this->sent)->startsWith('HTTP/1.1 404');
    }

    public function testPassesFramesOutAndEmitsTheResponse(): void
    {
        $frame = new Frame\Binary(Stream::create('payload'));

        $frames = $this->handle(self::middleware(static function () use ($frame): ResponseInterface {
            \Fiber::suspend($frame);
            return new Response(202, body: 'accepted');
        }));

        Assert::same($frames, [$frame]);
        Assert::string($this->sent)->startsWith('HTTP/1.1 202');
        Assert::string($this->sent)->contains('accepted');
    }

    public function testResponseWithoutFrameIsOnlyEmitted(): void
    {
        $frames = $this->handle(self::middleware(static fn(): ResponseInterface => new Response(415, body: 'rejected')));

        Assert::same($frames, []);
        Assert::string($this->sent)->startsWith('HTTP/1.1 415');
    }

    public function testFailingMiddlewareGets500(): void
    {
        $frames = $this->handle(self::middleware(static fn(): never => throw new \RuntimeException('boom')));

        Assert::same($frames, []);
        Assert::string($this->sent)->startsWith('HTTP/1.1 500');
    }

    /**
     * @param \Closure(): ResponseInterface $answer
     */
    private static function middleware(\Closure $answer): Middleware
    {
        return new class($answer) implements Middleware {
            public function __construct(
                private readonly \Closure $answer,
            ) {}

            public function handle(ServerRequestInterface $request, callable $next): ResponseInterface
            {
                return ($this->answer)();
            }
        };
    }

    /**
     * @return list<Frame>
     */
    private function handle(Middleware ...$middlewares): array
    {
        $client = \Mockery::mock(StreamClient::class)->shouldIgnoreMissing();
        $client->shouldReceive('sendData')->andReturnUsing(function (string $data): bool {
            $this->sent .= $data;
            return true;
        });

        $frames = (new MiddlewareHandler($middlewares))
            ->handle($client, new ServerRequest('POST', '/v1/traces'), static fn() => []);
        // The handler suspends its fiber between steps.
        $fiber = new \Fiber(static fn(): array => \iterator_to_array($frames, false));
        $fiber->start();
        while (!$fiber->isTerminated()) {
            $fiber->resume();
        }

        /** @var list<Frame> */
        return $fiber->getReturn();
    }
}
