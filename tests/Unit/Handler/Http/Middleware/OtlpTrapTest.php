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
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Covers(OtlpTrap::class)]
#[Test]
final class OtlpTrapTest
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
        yield 'too large' => ['application/json', \gzencode(\str_repeat(' ', 11 * 1024 * 1024)), 413];
    }

    public static function provideTracesPaths(): \Generator
    {
        yield 'default path' => ['/v1/traces'];
        yield 'trailing slash' => ['/v1/traces/'];
        yield 'path prefix' => ['http://127.0.0.1:9912/otlp/v1/traces'];
    }

    public static function provideEmptyExports(): \Generator
    {
        yield 'empty object' => ['application/json', '{}', '{}'];
        yield 'empty resourceSpans' => ['application/json', '{"resourceSpans":[]}', '{}'];
        yield 'empty scopeSpans' => ['application/json', '{"resourceSpans":[{"scopeSpans":[{"spans":[]}]}]}', '{}'];
        yield 'empty protobuf' => ['application/x-protobuf', '', ''];
    }

    public static function provideUnreadGzipBodies(): iterable
    {
        yield 'broken' => ['"\x1f\x8b" . "not really gzip"', '400'];
        yield 'decompressed body too large' => ['\gzencode(\str_repeat(" ", 11 * 1024 * 1024))', '413'];
    }

    #[DataProvider('provideEmptyExports')]
    public function testEmptyExportSucceedsWithoutFrameAndDump(string $contentType, string $body, string $response): void
    {
        [$result, $frames] = $this->handleInFiber($this->request(body: $body, contentType: $contentType));

        Assert::same($result->getStatusCode(), 200);
        Assert::same((string) $result->getBody(), $response);
        Assert::true($result->hasHeader(Fallback::NO_DUMP_HEADER));
        Assert::same($frames, []);
    }

    public function testRejectsJsonListWith400(): void
    {
        [$response, $frames] = $this->handleInFiber($this->request(body: '[1,2]'));

        Assert::same($response->getStatusCode(), 400);
        Assert::same($frames, []);
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

        Assert::same($response->getStatusCode(), 200);
        Assert::same($response->getHeaderLine('Content-Type'), $contentType);
        Assert::same((string) $response->getBody(), $body);
        Assert::true($response->hasHeader(Fallback::NO_DUMP_HEADER));
        Assert::same($frames, []);
        Assert::same(\substr_count($output->fetch(), "OTLP: $signal are not supported yet"), 1);
    }

    #[DataProvider('provideRejectedRequests')]
    public function testRejectedRequestsAreNotDumpedButReported(string $contentType, string $body, int $status): void
    {
        $output = new BufferedOutput();
        $middleware = new OtlpTrap(new Logger($output));

        [$response] = $this->handleInFiber($this->request(body: $body, contentType: $contentType), $middleware);

        Assert::same($response->getStatusCode(), $status);
        Assert::true($response->hasHeader(Fallback::NO_DUMP_HEADER));
        Assert::same(\substr_count($output->fetch(), "OTLP: traces request rejected with $status: "), 1);
    }

    public function testRejectionReportEscapesReceivedText(): void
    {
        $output = new BufferedOutput();
        $request = $this->request(body: '{}');
        $decoder = new class implements TracesDecoder {
            public function decode(string $payload, \DateTimeImmutable $time): OtlpTraces
            {
                throw new \InvalidArgumentException("<fg=foo>bad</>\e[31m");
            }

            public function createSuccessResponse(): ResponseInterface
            {
                return new Response(200);
            }
        };
        $middleware = new OtlpTrap(new Logger($output), ['application/json' => $decoder]);

        $this->handleInFiber($request, $middleware);

        Assert::same($output->fetch(), "OTLP: traces request rejected with 400: <fg=foo>bad</>?[31m\n");
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

        Assert::same($result, $expected);
    }

    #[DataProvider('provideTracesPaths')]
    public function testAcceptsJsonTraces(string $uri): void
    {
        $time = new \DateTimeImmutable('2024-01-01 12:00:00');
        $request = $this->request(uri: $uri, body: self::PAYLOAD, attributes: ['begin_at' => $time]);

        [$response, $frames] = $this->handleInFiber($request);

        Assert::same($response->getStatusCode(), 200);
        Assert::same($response->getHeaderLine('Content-Type'), 'application/json');
        Assert::same((string) $response->getBody(), '{}');
        Assert::count($frames, 1);
        Assert::instanceOf($frames[0], OtlpTraces::class);
        Assert::same($frames[0]->countSpans(), 1);
        Assert::equals($frames[0]->time, $time);
    }

    public function testAcceptsContentTypeWithParameters(): void
    {
        $request = $this->request(body: self::PAYLOAD, contentType: 'Application/JSON; charset=utf-8');

        [$response, $frames] = $this->handleInFiber($request);

        Assert::same($response->getStatusCode(), 200);
        Assert::count($frames, 1);
    }

    public function testAcceptsGzipBody(): void
    {
        $request = $this->request(body: \gzencode(self::PAYLOAD), headers: ['Content-Encoding' => 'gzip']);

        [$response, $frames] = $this->handleInFiber($request);

        Assert::same($response->getStatusCode(), 200);
        Assert::count($frames, 1);
        Assert::same($frames[0]->resourceSpans[0]->scopeSpans[0]->spans[0]->name, 'test');
    }

    public function testAcceptsBodyAlreadyInflatedByHttpParser(): void
    {
        $request = $this->request(body: self::PAYLOAD, headers: ['Content-Encoding' => 'gzip']);

        [$response, $frames] = $this->handleInFiber($request);

        Assert::same($response->getStatusCode(), 200);
        Assert::count($frames, 1);
    }

    public function testAcceptsFixtureFromOpenTelemetryPhpExporter(): void
    {
        $request = $this->request(body: \file_get_contents(Info::TRAP_ROOT . '/tests/Stub/otlp-traces.json'));

        [$response, $frames] = $this->handleInFiber($request);

        Assert::same($response->getStatusCode(), 200);
        Assert::same($frames[0]->countSpans(), 4);
    }

    public function testRejectsMalformedJsonWith400(): void
    {
        $request = $this->request(body: '{"resourceSpans": [');

        [$response, $frames] = $this->handleInFiber($request);

        Assert::same($response->getStatusCode(), 400);
        Assert::same(\json_decode((string) $response->getBody(), true)['code'], 3);
        Assert::same($frames, []);
    }

    public function testRejectsInvalidSpanWith400(): void
    {
        $request = $this->request(body: '{"resourceSpans":[{"scopeSpans":[{"spans":[{"traceId":"zz"}]}]}]}');

        [$response, $frames] = $this->handleInFiber($request);

        Assert::same($response->getStatusCode(), 400);
        Assert::string((string) $response->getBody())->contains('traceId');
        Assert::same($frames, []);
    }

    public function testRejectsBrokenGzipWith400(): void
    {
        $request = $this->request(body: "\x1f\x8b" . 'not really gzip');

        [$response, $frames] = $this->handleInFiber($request);

        Assert::same($response->getStatusCode(), 400);
        Assert::same($frames, []);
    }

    public function testRejectsOversizedBodyWith413(): void
    {
        $body = \Mockery::mock(StreamInterface::class)->shouldIgnoreMissing();
        $body->shouldReceive('getSize')->andReturn(11 * 1024 * 1024);
        $request = $this->request()->withBody($body);

        [$response, $frames] = $this->handleInFiber($request);

        Assert::same($response->getStatusCode(), 413);
        Assert::same($frames, []);
    }

    /**
     * Runs in a separate process without an error handler: only then does PHP print such warnings.
     */
    #[DataProvider('provideUnreadGzipBodies')]
    public function testGzipBodyLeftUnreadPrintsNoWarning(string $bodyExpression, string $status): void
    {
        $script = <<<PHP
            require \$argv[1];
            \$request = (new \\Nyholm\\Psr7\\ServerRequest('POST', '/v1/traces'))
                ->withHeader('Content-Type', 'application/json')
                ->withBody(\\Nyholm\\Psr7\\Stream::create($bodyExpression));
            \$fiber = new \\Fiber(static fn() => (new \\Buggregator\\Trap\\Handler\\Http\\Middleware\\OtlpTrap())
                ->handle(\$request, static fn() => new \\Nyholm\\Psr7\\Response(500)));
            \$fiber->start();
            while (!\$fiber->isTerminated()) {
                \$fiber->resume();
            }
            echo \$fiber->getReturn()->getStatusCode();
            PHP;

        $process = \proc_open(
            [\PHP_BINARY, '-d', 'display_errors=stderr', '-r', $script, Info::TRAP_ROOT . '/vendor/autoload.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $stdout = \stream_get_contents($pipes[1]);
        $stderr = \stream_get_contents($pipes[2]);
        \proc_close($process);

        Assert::same($stdout, $status);
        Assert::same($stderr, '');
    }

    public function testRejectsOversizedDecompressedBodyWith413(): void
    {
        $request = $this->request(body: \gzencode(\str_repeat(' ', 11 * 1024 * 1024)));

        [$response, $frames] = $this->handleInFiber($request);

        Assert::same($response->getStatusCode(), 413);
        Assert::same($frames, []);
    }

    public function testRejectsUnknownBodySizeWith413(): void
    {
        $body = \Mockery::mock(StreamInterface::class)->shouldIgnoreMissing();
        $body->shouldReceive('getSize')->andReturn(null);
        $request = $this->request()->withBody($body);

        [$response] = $this->handleInFiber($request);

        Assert::same($response->getStatusCode(), 413);
    }

    public function testRegistersProtobufDecoderWhenPackagesAreInstalled(): void
    {
        Assert::true(ProtobufTracesDecoder::isAvailable(), 'The dev dependencies include the protobuf packages.');
        $request = $this->request(body: self::protobufFixture(), contentType: 'application/x-protobuf');

        [$response, $frames] = $this->handleInFiber($request);

        Assert::same($response->getStatusCode(), 200);
        Assert::same($response->getHeaderLine('Content-Type'), 'application/x-protobuf');
        Assert::same((string) $response->getBody(), '');
        Assert::count($frames, 1);
        Assert::same($frames[0]->countSpans(), 4);
    }

    public function testAcceptsGzipProtobufBody(): void
    {
        $request = $this->request(
            body: \gzencode(self::protobufFixture()),
            contentType: 'application/x-protobuf',
            headers: ['Content-Encoding' => 'gzip'],
        );

        [$response, $frames] = $this->handleInFiber($request);

        Assert::same($response->getStatusCode(), 200);
        Assert::same($frames[0]->countSpans(), 4);
    }

    public function testRejectsMalformedProtobufWith400(): void
    {
        $request = $this->request(body: "\x0a\xff\xff\xff", contentType: 'application/x-protobuf');

        [$response, $frames] = $this->handleInFiber($request);

        Assert::same($response->getStatusCode(), 400);
        Assert::same($response->getHeaderLine('Content-Type'), 'application/x-protobuf');
        [$code, $message] = self::decodeStatus((string) $response->getBody());
        Assert::same($code, 3);
        Assert::string($message)->startsWith('Invalid protobuf message');
        Assert::same($frames, []);
    }

    public function testProtobufStatusOfOversizedBody(): void
    {
        $body = \Mockery::mock(StreamInterface::class)->shouldIgnoreMissing();
        $body->shouldReceive('getSize')->andReturn(11 * 1024 * 1024);
        $request = $this->request(contentType: 'application/x-protobuf')->withBody($body);

        [$response] = $this->handleInFiber($request);

        Assert::same($response->getStatusCode(), 413);
        Assert::same(self::decodeStatus((string) $response->getBody()), [8, 'Request body is too large.']);
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

        Assert::same($jsonResponse->getStatusCode(), 413);
        Assert::same(\json_decode((string) $jsonResponse->getBody(), true), ['code' => 8, 'message' => 'Too many spans in one request, the limit is 3.']);
        Assert::true($jsonResponse->hasHeader(Fallback::NO_DUMP_HEADER));
        Assert::same($protobufResponse->getStatusCode(), 413);
        Assert::same(self::decodeStatus((string) $protobufResponse->getBody()), [8, 'Too many spans in one request, the limit is 3.']);
        Assert::same([...$jsonFrames, ...$protobufFrames], []);
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

        Assert::same(self::decodeStatus((string) $response->getBody()), [3, \str_repeat('x', 200)]);
    }

    public function testJsonOnlyModeRejectsProtobufWith415AndReportsOnce(): void
    {
        $output = new BufferedOutput();
        $middleware = new OtlpTrap(new Logger($output), ['application/json' => new JsonTracesDecoder()]);
        $request = $this->request(body: self::protobufFixture(), contentType: 'application/x-protobuf');

        [$first, $frames] = $this->handleInFiber($request, $middleware);
        [$second] = $this->handleInFiber($request, $middleware);

        Assert::same($first->getStatusCode(), 415);
        Assert::same($second->getStatusCode(), 415);
        Assert::same(self::decodeStatus((string) $first->getBody())[0], 12);
        Assert::same($frames, []);
        Assert::same(\substr_count($output->fetch(), 'composer require open-telemetry/gen-otlp-protobuf'), 1);
    }

    public function testJsonOnlyModeAcceptsJson(): void
    {
        $middleware = new OtlpTrap(tracesDecoders: ['application/json' => new JsonTracesDecoder()]);

        [$response, $frames] = $this->handleInFiber($this->request(body: self::PAYLOAD), $middleware);

        Assert::same($response->getStatusCode(), 200);
        Assert::same((string) $response->getBody(), '{}');
        Assert::count($frames, 1);
    }

    public function testProtobufOnlyModeRejectsJsonWith415(): void
    {
        $middleware = new OtlpTrap(tracesDecoders: ['application/x-protobuf' => new ProtobufTracesDecoder()]);

        [$response, $frames] = $this->handleInFiber($this->request(body: self::PAYLOAD), $middleware);

        Assert::same($response->getStatusCode(), 415);
        Assert::string((string) $response->getBody())->contains('application/json');
        Assert::true($response->hasHeader(Fallback::NO_DUMP_HEADER));
        Assert::same($frames, []);
    }

    public function testFormPostedToLogsPathIsDumpedAsHttp(): void
    {
        $request = $this->request(uri: '/api/v1/logs', body: 'a=1', contentType: 'application/x-www-form-urlencoded');
        $client = \Mockery::mock(StreamClient::class)->shouldIgnoreMissing();
        $client->shouldReceive('sendData')->andReturn(true);

        $frames = (new Fallback([new OtlpTrap()]))->handle($client, $request, static fn(): array => []);
        $fiber = new \Fiber(static fn(): array => \iterator_to_array($frames, false));
        $fiber->start();
        while (!$fiber->isTerminated()) {
            $fiber->resume();
        }

        Assert::count($fiber->getReturn(), 1);
        Assert::instanceOf($fiber->getReturn()[0], Frame\Http::class);
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
                Assert::fail("Unexpected tag $tag.");
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
