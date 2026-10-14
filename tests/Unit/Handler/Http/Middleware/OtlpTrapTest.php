<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Handler\Http\Middleware;

use Buggregator\Trap\Handler\Http\Handler\Fallback;
use Buggregator\Trap\Handler\Http\Middleware\OtlpTrap;
use Buggregator\Trap\Handler\Http\Middleware\OtlpTrap\JsonTracesDecoder;
use Buggregator\Trap\Handler\Http\Middleware\OtlpTrap\ProtobufTracesDecoder;
use Buggregator\Trap\Handler\Http\Middleware\OtlpTrap\TracesDecoder;
use Buggregator\Trap\Info;
use Buggregator\Trap\Logger;
use Buggregator\Trap\Proto\Frame;
use Buggregator\Trap\Proto\Frame\Otlp\OtlpTraces;
use Buggregator\Trap\Traffic\StreamClient;
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
        yield 'GET traces' => ['GET', '/v1/traces'];
        yield 'GET logs' => ['GET', '/v1/logs'];
        yield 'other path' => ['POST', '/api/traces'];
        yield 'form to logs' => ['POST', '/api/v1/logs', 'application/x-www-form-urlencoded'];
        yield 'form to metrics' => ['POST', '/v1/metrics', 'multipart/form-data; boundary=x'];
        yield 'text to traces' => ['POST', '/hooks/v1/traces', 'text/plain'];
        yield 'no content type' => ['POST', '/v1/traces', ''];
    }

    public static function provideDroppedSignals(): \Generator
    {
        yield 'logs, JSON' => ['/v1/logs', 'application/json', '{}', 'logs'];
        yield 'metrics, JSON' => ['/v1/metrics/', 'application/json', '{}', 'metrics'];
        yield 'logs, protobuf' => ['/otlp/v1/logs', 'application/x-protobuf', '', 'logs'];
        yield 'metrics, protobuf' => ['/v1/metrics', 'application/x-protobuf', '', 'metrics'];
    }

    public static function provideRejectedRequests(): \Generator
    {
        yield 'malformed JSON' => ['application/json', '{"resourceSpans": [', 400];
        yield 'too large' => ['application/json', \gzencode(\str_repeat(' ', 5 * 1024 * 1024)), 413];
    }

    public static function provideTracesPaths(): \Generator
    {
        yield 'default path' => ['/v1/traces'];
        yield 'trailing slash' => ['/v1/traces/'];
        yield 'path prefix' => ['http://127.0.0.1:9912/otlp/v1/traces'];
    }

    #[DataProvider('provideDroppedSignals')]
    public function testDropsUnsupportedSignalsWithSuccess(
        string $uri,
        string $contentType,
        string $body,
        string $signal,
    ): void {
        $output = new BufferedOutput();
        $middleware = new OtlpTrap(new Logger($output));
        $request = $this->request(uri: $uri, body: 'payload', contentType: $contentType);

        [$response, $frames] = $this->handleInFiber($request, $middleware);
        $this->handleInFiber($request, $middleware);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($contentType, $response->getHeaderLine('Content-Type'));
        self::assertSame($body, (string) $response->getBody());
        self::assertTrue($response->hasHeader(Fallback::NO_DUMP_HEADER));
        self::assertSame([], $frames);
        self::assertSame(1, \substr_count($output->fetch(), "OTLP: $signal are not supported yet"));
    }

    #[DataProvider('provideRejectedRequests')]
    public function testRejectedRequestsAreNotDumped(string $contentType, string $body, int $status): void
    {
        [$response] = $this->handleInFiber($this->request(body: $body, contentType: $contentType));

        self::assertSame($status, $response->getStatusCode());
        self::assertTrue($response->hasHeader(Fallback::NO_DUMP_HEADER));
    }

    #[DataProvider('providePassedThroughRequests')]
    public function testPassesThroughOtherRequests(
        string $method,
        string $uri,
        string $contentType = 'application/json',
    ): void {
        $expected = new Response(200);
        $request = $this->request(method: $method, uri: $uri, body: self::PAYLOAD, contentType: $contentType);

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
        $body->method('getSize')->willReturn(5 * 1024 * 1024);
        $request = $this->request()->withBody($body);

        [$response, $frames] = $this->handleInFiber($request);

        self::assertSame(413, $response->getStatusCode());
        self::assertSame([], $frames);
    }

    public function testRejectsOversizedDecompressedBodyWith413(): void
    {
        $request = $this->request(body: \gzencode(\str_repeat(' ', 5 * 1024 * 1024)));

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

    public function testRegistersProtobufDecoderWhenPackagesAreInstalled(): void
    {
        self::assertTrue(ProtobufTracesDecoder::isAvailable(), 'The dev dependencies include the protobuf packages.');
        $request = $this->request(body: self::protobufFixture(), contentType: 'application/x-protobuf');

        [$response, $frames] = $this->handleInFiber($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/x-protobuf', $response->getHeaderLine('Content-Type'));
        self::assertSame('', (string) $response->getBody());
        self::assertCount(1, $frames);
        self::assertSame(4, $frames[0]->countSpans());
    }

    public function testAcceptsGzipProtobufBody(): void
    {
        $request = $this->request(
            body: \gzencode(self::protobufFixture()),
            contentType: 'application/x-protobuf',
            headers: ['Content-Encoding' => 'gzip'],
        );

        [$response, $frames] = $this->handleInFiber($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(4, $frames[0]->countSpans());
    }

    public function testRejectsMalformedProtobufWith400(): void
    {
        $request = $this->request(body: "\x0a\xff\xff\xff", contentType: 'application/x-protobuf');

        [$response, $frames] = $this->handleInFiber($request);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('application/x-protobuf', $response->getHeaderLine('Content-Type'));
        [$code, $message] = self::decodeStatus((string) $response->getBody());
        self::assertSame(3, $code);
        self::assertStringStartsWith('Invalid protobuf message', $message);
        self::assertSame([], $frames);
    }

    public function testProtobufStatusOfOversizedBody(): void
    {
        $body = $this->createMock(StreamInterface::class);
        $body->method('getSize')->willReturn(5 * 1024 * 1024);
        $request = $this->request(contentType: 'application/x-protobuf')->withBody($body);

        [$response] = $this->handleInFiber($request);

        self::assertSame(413, $response->getStatusCode());
        self::assertSame([8, 'Request body is too large.'], self::decodeStatus((string) $response->getBody()));
    }

    public function testRejectsTooManySpansWith413(): void
    {
        $json = new JsonTracesDecoder(3);
        $middleware = new OtlpTrap(tracesDecoders: [
            'application/json' => $json,
            'application/x-protobuf' => new ProtobufTracesDecoder($json),
        ]);
        $jsonRequest = $this->request(body: \file_get_contents(Info::TRAP_ROOT . '/tests/Stub/otlp-traces.json'));
        $protobufRequest = $this->request(body: self::protobufFixture(), contentType: 'application/x-protobuf');

        [$jsonResponse, $jsonFrames] = $this->handleInFiber($jsonRequest, $middleware);
        [$protobufResponse, $protobufFrames] = $this->handleInFiber($protobufRequest, $middleware);

        self::assertSame(413, $jsonResponse->getStatusCode());
        self::assertSame(
            ['code' => 8, 'message' => 'Too many spans in one request, the limit is 3.'],
            \json_decode((string) $jsonResponse->getBody(), true),
        );
        self::assertTrue($jsonResponse->hasHeader(Fallback::NO_DUMP_HEADER));
        self::assertSame(413, $protobufResponse->getStatusCode());
        self::assertSame(
            [8, 'Too many spans in one request, the limit is 3.'],
            self::decodeStatus((string) $protobufResponse->getBody()),
        );
        self::assertSame([], [...$jsonFrames, ...$protobufFrames]);
    }

    public function testProtobufStatusEncodesLongMessages(): void
    {
        $decoder = new class implements TracesDecoder {
            public function decode(string $payload, \DateTimeImmutable $time): OtlpTraces
            {
                throw new \InvalidArgumentException(\str_repeat('x', 200));
            }

            public function createSuccessResponse(): ResponseInterface
            {
                return new Response(200);
            }
        };
        $middleware = new OtlpTrap(tracesDecoders: ['application/x-protobuf' => $decoder]);
        $request = $this->request(body: 'payload', contentType: 'application/x-protobuf');

        [$response] = $this->handleInFiber($request, $middleware);

        self::assertSame([3, \str_repeat('x', 200)], self::decodeStatus((string) $response->getBody()));
    }

    public function testJsonOnlyModeRejectsProtobufWith415AndReportsOnce(): void
    {
        $output = new BufferedOutput();
        $middleware = new OtlpTrap(new Logger($output), ['application/json' => new JsonTracesDecoder()]);
        $request = $this->request(body: self::protobufFixture(), contentType: 'application/x-protobuf');

        [$first, $frames] = $this->handleInFiber($request, $middleware);
        [$second] = $this->handleInFiber($request, $middleware);

        self::assertSame(415, $first->getStatusCode());
        self::assertSame(415, $second->getStatusCode());
        self::assertSame(12, self::decodeStatus((string) $first->getBody())[0]);
        self::assertSame([], $frames);
        self::assertSame(1, \substr_count($output->fetch(), 'composer require open-telemetry/gen-otlp-protobuf'));
    }

    public function testJsonOnlyModeAcceptsJson(): void
    {
        $middleware = new OtlpTrap(tracesDecoders: ['application/json' => new JsonTracesDecoder()]);

        [$response, $frames] = $this->handleInFiber($this->request(body: self::PAYLOAD), $middleware);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{}', (string) $response->getBody());
        self::assertCount(1, $frames);
    }

    public function testProtobufOnlyModeRejectsJsonWith415(): void
    {
        $middleware = new OtlpTrap(tracesDecoders: ['application/x-protobuf' => new ProtobufTracesDecoder()]);

        [$response, $frames] = $this->handleInFiber($this->request(body: self::PAYLOAD), $middleware);

        self::assertSame(415, $response->getStatusCode());
        self::assertStringContainsString('application/json', (string) $response->getBody());
        self::assertTrue($response->hasHeader(Fallback::NO_DUMP_HEADER));
        self::assertSame([], $frames);
    }

    public function testFormPostedToLogsPathIsDumpedAsHttp(): void
    {
        $request = $this->request(uri: '/api/v1/logs', body: 'a=1', contentType: 'application/x-www-form-urlencoded');
        $client = $this->createMock(StreamClient::class);
        $client->method('sendData')->willReturn(true);

        $frames = (new Fallback([new OtlpTrap()]))->handle($client, $request, static fn(): array => []);
        $fiber = new \Fiber(static fn(): array => \iterator_to_array($frames, false));
        $fiber->start();
        while (!$fiber->isTerminated()) {
            $fiber->resume();
        }

        self::assertCount(1, $fiber->getReturn());
        self::assertInstanceOf(Frame\Http::class, $fiber->getReturn()[0]);
    }

    /**
     * Reads `google.rpc.Status` fields 1 `code` and 2 `message`.
     *
     * @return array{int, string}
     */
    private static function decodeStatus(string $bytes): array
    {
        $offset = 0;
        $varint = static function () use ($bytes, &$offset): int {
            $value = 0;
            $shift = 0;
            do {
                $byte = \ord($bytes[$offset++]);
                $value |= ($byte & 0x7F) << $shift;
                $shift += 7;
            } while ($byte & 0x80);

            return $value;
        };

        $code = 0;
        $message = '';
        while ($offset < \strlen($bytes)) {
            $tag = $varint();
            if ($tag === 0x08) {
                $code = $varint();
            } elseif ($tag === 0x12) {
                $length = $varint();
                $message = \substr($bytes, $offset, $length);
                $offset += $length;
            } else {
                self::fail("Unexpected tag $tag.");
            }
        }

        return [$code, $message];
    }

    private static function protobufFixture(): string
    {
        return \file_get_contents(Info::TRAP_ROOT . '/tests/Stub/otlp-traces.pb');
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
