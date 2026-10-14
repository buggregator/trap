<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Handler\Http\Middleware;

use Buggregator\Trap\Handler\Http\Middleware\OtlpTrap;
use Buggregator\Trap\Info;
use Buggregator\Trap\Logger;
use Buggregator\Trap\Proto\Frame\Otlp\OtlpTraces;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\Console\Output\BufferedOutput;

#[CoversClass(OtlpTrap::class)]
final class OtlpTrapTest extends TestCase
{
    private const PAYLOAD = '{"resourceSpans":[{"scopeSpans":[{"spans":[{"traceId":"5b8efff798038103d269b633813fc60c",'
        . '"spanId":"eee19b7ec3c1b174","name":"test","kind":1,"startTimeUnixNano":"1","endTimeUnixNano":"2"}]}]}]}';

    public static function providePassedThroughRequests(): \Generator
    {
        yield 'logs signal' => ['POST', '/v1/logs'];
        yield 'metrics signal' => ['POST', '/v1/metrics'];
        yield 'GET traces' => ['GET', '/v1/traces'];
        yield 'other path' => ['POST', '/api/traces'];
    }

    public static function provideTracesPaths(): \Generator
    {
        yield 'default path' => ['/v1/traces'];
        yield 'trailing slash' => ['/v1/traces/'];
        yield 'path prefix' => ['http://127.0.0.1:9912/otlp/v1/traces'];
    }

    #[DataProvider('providePassedThroughRequests')]
    public function testPassesThroughOtherRequests(string $method, string $uri): void
    {
        $expected = new Response(200);
        $request = $this->request(method: $method, uri: $uri, body: self::PAYLOAD);

        $result = (new OtlpTrap())->handle($request, static fn(): ResponseInterface => $expected);

        self::assertSame($expected, $result);
    }

    #[DataProvider('provideTracesPaths')]
    public function testAcceptsJsonTraces(string $uri): void
    {
        $time = new \DateTimeImmutable('2024-01-01 12:00:00');
        $request = $this->request(uri: $uri, body: self::PAYLOAD, attributes: ['begin_at' => $time]);

        [$response, $frames] = $this->handleInFiber($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('{}', (string) $response->getBody());
        self::assertCount(1, $frames);
        self::assertInstanceOf(OtlpTraces::class, $frames[0]);
        self::assertSame(1, $frames[0]->countSpans());
        self::assertEquals($time, $frames[0]->time);
    }

    public function testAcceptsContentTypeWithParameters(): void
    {
        $request = $this->request(body: self::PAYLOAD, contentType: 'Application/JSON; charset=utf-8');

        [$response, $frames] = $this->handleInFiber($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $frames);
    }

    public function testAcceptsGzipBody(): void
    {
        $request = $this->request(body: \gzencode(self::PAYLOAD), headers: ['Content-Encoding' => 'gzip']);

        [$response, $frames] = $this->handleInFiber($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $frames);
        self::assertSame('test', $frames[0]->resourceSpans[0]->scopeSpans[0]->spans[0]->name);
    }

    public function testAcceptsBodyAlreadyInflatedByHttpParser(): void
    {
        $request = $this->request(body: self::PAYLOAD, headers: ['Content-Encoding' => 'gzip']);

        [$response, $frames] = $this->handleInFiber($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $frames);
    }

    public function testAcceptsFixtureFromOpenTelemetryPhpExporter(): void
    {
        $request = $this->request(body: \file_get_contents(Info::TRAP_ROOT . '/tests/Stub/otlp-traces.json'));

        [$response, $frames] = $this->handleInFiber($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(4, $frames[0]->countSpans());
    }

    public function testRejectsMalformedJsonWith400(): void
    {
        $request = $this->request(body: '{"resourceSpans": [');

        [$response, $frames] = $this->handleInFiber($request);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(3, \json_decode((string) $response->getBody(), true)['code']);
        self::assertSame([], $frames);
    }

    public function testRejectsInvalidSpanWith400(): void
    {
        $request = $this->request(body: '{"resourceSpans":[{"scopeSpans":[{"spans":[{"traceId":"zz"}]}]}]}');

        [$response, $frames] = $this->handleInFiber($request);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('traceId', (string) $response->getBody());
        self::assertSame([], $frames);
    }

    public function testRejectsBrokenGzipWith400(): void
    {
        $request = $this->request(body: "\x1f\x8b" . 'not really gzip');

        [$response, $frames] = $this->handleInFiber($request);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], $frames);
    }

    public function testRejectsOversizedBodyWith413(): void
    {
        $body = $this->createMock(StreamInterface::class);
        $body->method('getSize')->willReturn(11 * 1024 * 1024);
        $request = $this->request()->withBody($body);

        [$response, $frames] = $this->handleInFiber($request);

        self::assertSame(413, $response->getStatusCode());
        self::assertSame([], $frames);
    }

    public function testRejectsOversizedDecompressedBodyWith413(): void
    {
        $request = $this->request(body: \gzencode(\str_repeat(' ', 11 * 1024 * 1024)));

        [$response, $frames] = $this->handleInFiber($request);

        self::assertSame(413, $response->getStatusCode());
        self::assertSame([], $frames);
    }

    public function testRejectsUnknownBodySizeWith413(): void
    {
        $body = $this->createMock(StreamInterface::class);
        $body->method('getSize')->willReturn(null);
        $request = $this->request()->withBody($body);

        [$response] = $this->handleInFiber($request);

        self::assertSame(413, $response->getStatusCode());
    }

    public function testRejectsProtobufWith415AndReportsOnce(): void
    {
        $output = new BufferedOutput();
        $middleware = new OtlpTrap(new Logger($output));
        $request = $this->request(body: "\n\x02\x0a\x00", contentType: 'application/x-protobuf');

        [$first, $frames] = $this->handleInFiber($request, $middleware);
        [$second] = $this->handleInFiber($request, $middleware);

        self::assertSame(415, $first->getStatusCode());
        self::assertSame(415, $second->getStatusCode());
        self::assertSame(12, \json_decode((string) $first->getBody(), true)['code']);
        self::assertSame([], $frames);
        self::assertSame(1, \substr_count($output->fetch(), 'protobuf encoding is not supported'));
    }

    public function testRejectsUnknownContentTypeWith415(): void
    {
        $request = $this->request(body: self::PAYLOAD, contentType: 'text/plain');

        [$response, $frames] = $this->handleInFiber($request);

        self::assertSame(415, $response->getStatusCode());
        self::assertStringContainsString('text/plain', (string) $response->getBody());
        self::assertSame([], $frames);
    }

    /**
     * @return array{ResponseInterface, list<OtlpTraces>}
     */
    private function handleInFiber(ServerRequestInterface $request, ?OtlpTrap $middleware = null): array
    {
        $middleware ??= new OtlpTrap();
        $fiber = new \Fiber(static fn(): ResponseInterface => $middleware->handle(
            $request,
            static fn(): ResponseInterface => new Response(500),
        ));

        $frames = [];
        $frame = $fiber->start();
        while (!$fiber->isTerminated()) {
            $frame instanceof OtlpTraces and $frames[] = $frame;
            $frame = $fiber->resume();
        }

        return [$fiber->getReturn(), $frames];
    }

    private function request(
        string $method = 'POST',
        string $uri = '/v1/traces',
        ?string $body = null,
        string $contentType = 'application/json',
        array $headers = [],
        array $attributes = [],
    ): ServerRequestInterface {
        $request = (new ServerRequest($method, $uri))->withHeader('Content-Type', $contentType);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            $request = $request->withBody(Stream::create($body));
        }

        foreach ($attributes as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }

        return $request;
    }
}
