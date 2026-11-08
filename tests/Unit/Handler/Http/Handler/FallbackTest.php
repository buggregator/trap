<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Handler\Http\Handler;

use Buggregator\Trap\Handler\Http\Handler\Fallback;
use Buggregator\Trap\Handler\Http\Middleware;
use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Traffic\StreamClient;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Covers(Fallback::class)]
#[Test]
final class FallbackTest
{
    private string $sent = '';

    #[BeforeTest]
    public function resetSent(): void
    {
        $this->sent = '';
    }

    public function testDumpsRequestWithoutFrame(): void
    {
        $frames = $this->handle(new Response(404));

        Assert::count($frames, 1);
        Assert::instanceOf($frames[0], Frame\Http::class);
    }

    public function testNoDumpHeaderSuppressesDumpAndIsNotSent(): void
    {
        $frames = $this->handle(new Response(415, [Fallback::NO_DUMP_HEADER => '1'], 'rejected'));

        Assert::same($frames, []);
        Assert::string($this->sent)->startsWith('HTTP/1.1 415');
        Assert::string($this->sent)->contains('rejected');
        Assert::string($this->sent)->ignoringCase()->notContains(Fallback::NO_DUMP_HEADER);
    }

    /**
     * @return list<Frame>
     */
    private function handle(ResponseInterface $response): array
    {
        $client = \Mockery::mock(StreamClient::class)->shouldIgnoreMissing();
        $client->shouldReceive('sendData')->andReturnUsing(function (string $data): bool {
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
