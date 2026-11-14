<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Traffic\Dispatcher;

use Buggregator\Trap\Module\HttpDump\Frame as HttpFrame;
use Buggregator\Trap\Module\HttpDump\Http\Dump;
use Buggregator\Trap\Server\Handler\Http\RequestHandler;
use Buggregator\Trap\Server\Proto\Frame;
use Buggregator\Trap\Server\Traffic\Dispatcher\Http;
use Buggregator\Trap\Server\Traffic\StreamClient;
use Buggregator\Trap\Test\Mock\StreamClientMock;
use Psr\Http\Message\ServerRequestInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Data\DataSet;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Covers(Http::class)]
#[Test]
final class HttpTest
{
    private string $sent = '';

    public static function detectProvider()
    {
        yield ["GET /foo HTTP/1.1\r\n", true];
        yield ["GET /foo HTTP/1.1\r\nHost: 127.0.0.1:9912\r\nUser-Agent: Mozilla/5.0 (Windows NT 10", true];
        yield ["GET /foo HTTP/1.1\r\nHost: 127.0.0.1:9912\r\n", true,];
        yield ['POST /foo HT', null];
        yield ['GET /foo HTTP/1.1', null];
        yield ["BUGGREGATOR /foo HTTP/1.1\r\n", false];
        yield ["DELETE /foo HTTP/1.1\r\n", true];
        yield ["GET  HTTP/1.1\r\n", false];
        yield [<<<HTTP
            GET /_nuxt/index.30fc2cdf.js HTTP/1.1\r
            Host: 127.0.0.
            HTTP, true];
        yield ['GET /_nuxt/index.30fc2cdf.js HTTP/1.1\r', null];
        yield [<<<HTTP
        GET /_nuxt/_plugin-vue_export-helper.c27b6911.js HTTP/1.1\r
        Host: 127.0.0.1:8000\r
        Connection: keep-alive\r
        sec-ch-ua: "Chromium";v="118", "YaBrowser";v="23", "Not=A?Brand";v="99"\r
        Origin: http://127.0.0.1:8000\r
        DNT: 1\r
        sec-ch-ua-mobile: ?0\r
        User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/118.0.5993.731 YaBrowser/23.11.1.731 Yowser/2.5 Safari/537.36\r
        sec-ch-ua-platform: "Windows"\r
        Accept: application/signed-exchange;v=b3;q=0.7,*/*;q=0.8\r
        Purpose: prefetch\r
        Sec-Fetch-Site: same-origin\r
        Sec-Fetch-Mode: cors\r
        Sec-Fetch-Dest: empty\r
        Referer: http://127.0.0.1:8000/\r
        Accept-Encoding: gzip, deflate, br\r
        Accept-Language: ru,en;q=0.9\r
        \r\n
        HTTP, true];
    }

    #[BeforeTest]
    public function resetSent(): void
    {
        $this->sent = '';
    }

    #[DataProvider('detectProvider')]
    public function testDetect(string $data, ?bool $expected): void
    {
        $dispatcher = new Http();
        Assert::same($dispatcher->detect($data, new \DateTimeImmutable()), $expected);
    }

    public function testRequestNoMiddlewareAnswersGets404AndNoFrame(): void
    {
        $frames = $this->dispatch(new Http(), $this->createStream());

        Assert::same($frames, []);
        Assert::string($this->sent)->startsWith('HTTP/1.1 404');
    }

    public function testDumpMiddlewareGetsBeginAt(): void
    {
        $stream = $this->createStream();

        $frames = $this->dispatch(new Http([new Dump()]), $stream);

        Assert::count($frames, 1);
        Assert::instanceOf($frames[0], HttpFrame::class);
        Assert::same($frames[0]->request->getUri()->getPath(), '/foo');
        Assert::same($frames[0]->time, $stream->getCreatedAt());
        Assert::string($this->sent)->startsWith('HTTP/1.1 404');
    }

    public function testSilentModeYieldsNoFramesButStillResponds(): void
    {
        $frames = $this->dispatch(new Http(silentMode: true), $this->createStream());

        Assert::same($frames, []);
        Assert::string($this->sent)->startsWith('HTTP/1.1 404');
    }

    public function testCustomHandlerRunsBeforeMiddlewaresAndGetsBeginAt(): void
    {
        $stream = $this->createStream();
        $handler = new class implements RequestHandler {
            public ?ServerRequestInterface $request = null;

            public function handle(StreamClient $streamClient, ServerRequestInterface $request, callable $next): iterable
            {
                $this->request = $request;
                yield new HttpFrame($request);
                $streamClient->disconnect();
            }
        };

        $frames = $this->dispatch(new Http(handlers: [$handler]), $stream);

        Assert::count($frames, 1);
        Assert::same($frames[0]->request, $handler->request);
        Assert::same($handler->request?->getAttribute('begin_at'), $stream->getCreatedAt());
        Assert::same($this->sent, '', 'The middleware handler must not respond.');
    }

    public function testGzipBodyInflatingPastLimitIsAnsweredWith413BeforeHandlers(): void
    {
        $handler = new class implements RequestHandler {
            public bool $called = false;

            public function handle(StreamClient $streamClient, ServerRequestInterface $request, callable $next): iterable
            {
                $this->called = true;
                yield from $next($streamClient, $request);
            }
        };
        $dispatcher = new Http([new Dump()], [$handler], maxInflatedBodySize: 1024);

        $frames = $this->dispatch($dispatcher, $this->createGzipPostStream(\str_repeat('a', 1025)));

        Assert::same($frames, []);
        Assert::false($handler->called);
        Assert::string($this->sent)->startsWith('HTTP/1.1 413');
        Assert::string($this->sent)->contains('more than 1024 bytes. The limit is set with TRAP_HTTP_MAX_INFLATED_SIZE.');
    }

    public function testGzipBodyWithinLimitReachesMiddlewaresInflated(): void
    {
        $dispatcher = new Http([new Dump()], maxInflatedBodySize: 1024);

        $frames = $this->dispatch($dispatcher, $this->createGzipPostStream($body = \str_repeat('a', 1024)));

        Assert::count($frames, 1);
        Assert::instanceOf($frames[0], HttpFrame::class);
        Assert::same((string) $frames[0]->request->getBody(), $body);
    }

    #[DataSet([0], 'zero')]
    #[DataSet([-1], 'negative')]
    public function testNonPositiveGzipBodyLimitFallsBackToDefault(int $limit): void
    {
        $dispatcher = new Http([new Dump()], maxInflatedBodySize: $limit);

        $frames = $this->dispatch($dispatcher, $this->createGzipPostStream($body = \str_repeat('a', 1024)));

        Assert::count($frames, 1);
        Assert::instanceOf($frames[0], HttpFrame::class);
        Assert::same((string) $frames[0]->request->getBody(), $body);
    }

    private function createGzipPostStream(string $body): StreamClient
    {
        $gzip = \gzencode($body);

        return $this->createStream(
            "POST /foo HTTP/1.1\r\n",
            "Host: localhost\r\n",
            "Content-Encoding: gzip\r\n",
            'Content-Length: ' . \strlen($gzip) . "\r\n",
            "\r\n",
            $gzip,
        );
    }

    private function createStream(string ...$chunks): StreamClient
    {
        $chunks = $chunks ?: ["GET /foo HTTP/1.1\r\n", "Host: localhost\r\n", "\r\n"];

        return StreamClientMock::createFromGenerator((function () use ($chunks): \Generator {
            foreach ($chunks as $chunk) {
                $this->sent .= (string) yield $chunk;
            }
            while (true) {
                $got = yield '';
                if (!\is_string($got)) {
                    return;
                }
                $this->sent .= $got;
            }
        })());
    }

    /**
     * @return list<Frame>
     */
    private function dispatch(Http $dispatcher, StreamClient $stream): array
    {
        // The middleware handler suspends its fiber between steps.
        $fiber = new \Fiber(static fn(): array => \iterator_to_array($dispatcher->dispatch($stream), false));
        $fiber->start();
        while (!$fiber->isTerminated()) {
            $fiber->resume();
        }

        return $fiber->getReturn();
    }
}
