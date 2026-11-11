<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\HttpDump\Http;

use Buggregator\Trap\Module\HttpDump\Frame;
use Buggregator\Trap\Module\HttpDump\Http\DebugPage;
use Buggregator\Trap\Module\HttpDump\Http\Dump;
use Buggregator\Trap\Server\Handler\Http\Middleware;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(Dump::class)]
#[Covers(DebugPage::class)]
#[Test]
final class DumpTest
{
    public function testDumpsRequestWithItsBeginTimeAndAnswers404(): void
    {
        $time = new \DateTimeImmutable('2024-01-02 03:04:05');
        $request = (new ServerRequest('POST', '/orders'))->withAttribute('begin_at', $time);

        [$response, $frames] = self::run(new Dump(), $request);

        Assert::same($response->getStatusCode(), 404);
        Assert::count($frames, 1);
        Assert::instanceOf($frames[0], Frame::class);
        Assert::same($frames[0]->request, $request);
        Assert::same($frames[0]->time, $time);
    }

    public function testDebugFormPostIsDumpedAndAnswered(): void
    {
        [$response, $frames] = self::run(new DebugPage(), new ServerRequest('POST', '/_debug/bar'), new Dump());

        Assert::same($response->getStatusCode(), 200);
        Assert::same((string) $response->getBody(), 'Hello World!');
        Assert::count($frames, 1);
    }

    public function testDebugPageIsNotDumped(): void
    {
        [$response, $frames] = self::run(new DebugPage(), new ServerRequest('GET', '/_debug'), new Dump());

        Assert::same($response->getStatusCode(), 200);
        Assert::string((string) $response->getBody())->contains('<form');
        Assert::same($frames, []);
    }

    public function testOtherRequestGoesOnPastDebugPage(): void
    {
        [$response, $frames] = self::run(new DebugPage(), new ServerRequest('GET', '/orders'), new Dump());

        Assert::same($response->getStatusCode(), 404);
        Assert::count($frames, 1);
    }

    /**
     * Runs the middleware the way the middleware handler does: in a fiber that suspends with frames.
     *
     * @return array{ResponseInterface, list<Frame>}
     */
    private static function run(Middleware $middleware, ServerRequestInterface $request, ?Middleware $next = null): array
    {
        $fiber = new \Fiber(static fn(): ResponseInterface => $middleware->handle(
            $request,
            static fn(ServerRequestInterface $request): ResponseInterface => $next?->handle(
                $request,
                static fn(): ResponseInterface => new Response(404),
            ) ?? new Response(404),
        ));

        $frames = [];
        $frame = $fiber->start();
        while (!$fiber->isTerminated()) {
            $frame instanceof Frame and $frames[] = $frame;
            $frame = $fiber->resume();
        }

        return [$fiber->getReturn(), $frames];
    }
}
