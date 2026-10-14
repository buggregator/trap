<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Handler\Http\Middleware;

use Buggregator\Trap\Handler\Http\Middleware\SentryTrap;
use Buggregator\Trap\Proto\Frame\Sentry;
use Buggregator\Trap\Proto\Frame\Sentry\SentryEnvelope;
use Buggregator\Trap\Proto\Frame\Sentry\SentryStore;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Covers(SentryTrap::class)]
#[Test]
final class SentryTrapTest
{
    public static function provideSentryStoreRequests(): \Generator
    {
        yield 'with sentry auth header' => [
            ['X-Sentry-Auth' => 'Sentry sentry_key=test'],
            '/api/sentry/store/',
            true,
        ];

        yield 'with buggregator event header' => [
            ['X-Buggregator-Event' => 'sentry'],
            '/api/sentry/store/',
            true,
        ];

        yield 'with sentry user info' => [
            [],
            'https://sentry:password@example.com/api/sentry/store/',
            false,
        ];
    }

    public static function provideNonSentryRequests(): \Generator
    {
        yield 'envelope without content type' => [
            ['Content-Type' => 'application/json'],
            '/api/sentry/envelope/',
        ];

        yield 'store without identifiers' => [
            [],
            '/api/sentry/store/',
        ];
    }

    public static function provideSentryEnvelopeRequests(): \Generator
    {
        yield [
            '/api/sentry/envelope/',
            ['Content-Type' => 'application/x-sentry-envelope'],
            "{\"event_id\":\"test123\"}\n{\"type\":\"event\"}\n{\"message\":\"test\"}",
        ];

        yield [
            '/api/1/envelope/',
            ['Content-Type' => 'application/x-sentry-envelope'],
            "{\"event_id\":\"test123\"}\n{\"type\":\"event\"}\n{\"message\":\"test\"}",
        ];
    }

    public function testHandlePassesThroughNonSentryRequests(): void
    {
        // Arrange
        $expectedResponse = new Response(200);
        $request = $this->request();
        $next = static fn() => $expectedResponse;

        // Act
        $middleware = new SentryTrap();
        $result = $middleware->handle($request, $next);

        // Assert
        Assert::same($result, $expectedResponse);
    }

    #[DataProvider('provideSentryEnvelopeRequests')]
    public function testHandleProcessesEnvelopeRequest(
        string $uri,
        array $headers,
        string $envelopeData,
    ): void {
        // Arrange
        $request = $this->request(uri: $uri, headers: $headers, body: $envelopeData);

        // Act
        [$response, $frames] = $this->handleInFiber($request);

        // Assert
        Assert::same($response->getStatusCode(), 200);
        Assert::instanceOf($frames[0], SentryEnvelope::class);
    }

    #[DataProvider('provideSentryStoreRequests')]
    public function testHandleProcessesStoreRequests(array $headers, string $uri, bool $catch): void
    {
        // Arrange
        $storeData = [
            'type' => 'store',
            'event_id' => 'test123',
            'timestamp' => 1234567890,
        ];
        $request = $this->request(
            uri: $uri,
            headers: $headers,
            body: $storeData,
        );

        // Act
        [$response, $frames] = $this->handleInFiber($request);

        // Assert
        if (!$catch) {
            Assert::count($frames, 0);
            return;
        }
        Assert::same($response->getStatusCode(), 200);
        Assert::instanceOf($frames[0], SentryStore::class);
        Assert::same($frames[0]->message, $storeData);
    }

    #[DataProvider('provideNonSentryRequests')]
    public function testHandleRejectsNonSentryRequests(array $headers, string $uri): void
    {
        // Arrange
        $expectedResponse = new Response(200);
        $request = $this->request(uri: $uri, headers: $headers);
        $next = static fn() => $expectedResponse;

        // Act
        $middleware = new SentryTrap();
        $result = $middleware->handle($request, $next);

        // Assert
        Assert::same($result, $expectedResponse);
    }

    public function testHandleReturns400ForInvalidJson(): void
    {
        // Arrange
        $request = $this->request(
            uri: '/api/sentry/store/',
            headers: ['X-Sentry-Auth' => 'test'],
            body: 'invalid json',
        );

        // Act
        $middleware = new SentryTrap();
        $result = $middleware->handle($request, static fn() => new Response(500));

        // Assert
        Assert::same($result->getStatusCode(), 400);
    }

    public function testHandleReturns400OnGeneralException(): void
    {
        // Arrange
        $body = \Mockery::mock(StreamInterface::class)->shouldIgnoreMissing();
        $body->shouldReceive('getSize')->andThrow(new \RuntimeException('Test error'));

        $request = $this->request(
            uri: '/api/sentry/envelope/',
            headers: ['Content-Type' => 'application/x-sentry-envelope'],
        )->withBody($body);

        // Act
        $middleware = new SentryTrap();
        $result = $middleware->handle($request, static fn() => new Response(500));

        // Assert
        Assert::same($result->getStatusCode(), 400);
    }

    public function testProcessStoreReturns413ForOversizedBody(): void
    {
        // Arrange
        $body = \Mockery::mock(StreamInterface::class)->shouldIgnoreMissing();
        $body->shouldReceive('getSize')->andReturn(3 * 1024 * 1024);

        $request = $this->request(
            uri: '/api/sentry/store/',
            headers: ['X-Sentry-Auth' => 'test'],
        )->withBody($body);

        // Act
        $middleware = new SentryTrap();
        $result = $middleware->handle($request, static fn() => new Response(500));

        // Assert
        Assert::same($result->getStatusCode(), 413);
    }

    public function testProcessStoreUsesBeginAtAttribute(): void
    {
        // Arrange
        $customTime = new \DateTimeImmutable('2023-01-01 12:00:00');
        $storeData = [
            'type' => 'store',
            'event_id' => 'test123',
            'timestamp' => 1234567890,
        ];
        $request = $this->request(
            uri: '/api/sentry/store/',
            headers: ['X-Sentry-Auth' => 'test'],
            body: $storeData,
            attributes: ['begin_at' => $customTime],
        );

        // Act
        [, $frames] = $this->handleInFiber($request);

        // Assert
        Assert::count($frames, 1);
        Assert::equals($frames[0]->time, $customTime);
    }

    private function handleInFiber(ServerRequestInterface $request, ?callable $next = null): array
    {
        $middleware = new SentryTrap();
        $next ??= static fn() => new Response(500);

        [$response, $frames] = $this->runInFiber(static function () use ($middleware, $request, $next) {
            try {
                return $middleware->handle($request, $next);
            } catch (\FiberError) {
                return new Response(200);
            }
        });

        return [$response, $frames];
    }

    /**
     * @param \Closure(): Response $callback
     * @return array{Response, list<Sentry>}
     */
    private function runInFiber(\Closure $callback): array
    {
        $frames = [];
        $fiber = new \Fiber($callback);
        $frame = $fiber->start();
        if ($frame !== null) {
            $frames[] = $frame;
        }

        do {
            if ($fiber->isTerminated()) {
                return [$fiber->getReturn(), $frames];
            }

            $frame = $fiber->resume();
            if ($frame !== null) {
                $frames[] = $frame;
            }
        } while (true);
    }

    private function request(
        string $method = 'POST',
        string $uri = '/api/test',
        array $headers = [],
        string|array|null $body = null,
        array $attributes = [],
    ): ServerRequestInterface {
        $request = new ServerRequest($method, $uri);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            $bodyStream = \is_array($body)
                ? Stream::create(\json_encode($body))
                : Stream::create($body);
            $request = $request->withBody($bodyStream);
        }

        foreach ($attributes as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }

        return $request;
    }
}
