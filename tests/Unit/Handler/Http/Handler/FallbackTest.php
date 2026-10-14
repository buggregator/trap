<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Handler\Http\Handler;

use Buggregator\Trap\Handler\Http\Handler\Fallback;
use Buggregator\Trap\Handler\Http\Middleware;
use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Traffic\StreamClient;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[CoversClass(Fallback::class)]
final class FallbackTest extends TestCase
{
    private string $sent = '';

    public function testDumpsRequestWithoutFrame(): void
    {
        $frames = $this->handle(new Response(404));

        self::assertCount(1, $frames);
        self::assertInstanceOf(Frame\Http::class, $frames[0]);
    }

    public function testNoDumpHeaderSuppressesDumpAndIsNotSent(): void
    {
        $frames = $this->handle(new Response(415, [Fallback::NO_DUMP_HEADER => '1'], 'rejected'));

        self::assertSame([], $frames);
        self::assertStringStartsWith('HTTP/1.1 415', $this->sent);
        self::assertStringContainsString('rejected', $this->sent);
        self::assertStringNotContainsStringIgnoringCase(Fallback::NO_DUMP_HEADER, $this->sent);
    }

    /**
     * @return list<Frame>
     */
    private function handle(ResponseInterface $response): array
    {
        $client = $this->createMock(StreamClient::class);
        $client->method('sendData')->willReturnCallback(function (string $data): bool {
            $this->sent .= $data;
            return true;
        });

        $middleware = new class($response) implements Middleware {
            public function __construct(
                private readonly ResponseInterface $response,
            ) {}

            public function handle(ServerRequestInterface $request, callable $next): ResponseInterface
            {
                return $this->response;
            }
        };

        $frames = (new Fallback([$middleware]))->handle($client, new ServerRequest('POST', '/v1/traces'), static fn() => []);
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
