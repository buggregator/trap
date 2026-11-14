<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Client\Profiler;

use Buggregator\Trap\Client\Profiler\Internal\HttpClient;
use Buggregator\Trap\Tests\Unit\Client\Profiler\Stub\HttpCatcher;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterClass;
use Testo\Lifecycle\BeforeClass;
use Testo\Test;

#[Test]
#[Covers(HttpClient::class)]
final class HttpClientTest
{
    private static HttpCatcher $server;

    #[BeforeClass]
    public static function startServer(): void
    {
        self::$server = new HttpCatcher();
    }

    #[AfterClass]
    public static function stopServer(): void
    {
        self::$server->close();
    }

    public function sendsBodyInChunks(): void
    {
        self::post([7, (static function (): \Generator {
            yield 'chunk';
            yield 's!';
        })()]);

        $requests = self::$server->requests();
        Assert::count($requests, 1);
        Assert::string($requests[0])->contains("Content-Length: 7\r\n");
        Assert::same(\explode("\r\n\r\n", $requests[0], 2)[1], 'chunks!');
    }

    public function failsWhenChunksAreNotOfTheSizeTold(): void
    {
        try {
            self::post([10, ['short']]);
            Assert::fail('The request is sent.');
        } catch (\RuntimeException $e) {
            Assert::string($e->getMessage())->contains('is not of the size told.');
        }
        self::$server->requests();
    }

    public function passesOnTheErrorOfChunksThatFail(): void
    {
        try {
            self::post([10, (static function (): \Generator {
                yield 'half';
                throw new \RuntimeException('The trace is gone.');
            })()]);
            Assert::fail('The request is sent.');
        } catch (\RuntimeException $e) {
            Assert::same($e->getMessage(), 'The trace is gone.');
        }
        self::$server->requests();
    }

    /**
     * @param string|array{int<0, max>, iterable<string>} $body
     */
    private static function post(string|array $body): void
    {
        (new HttpClient())->post('http', '127.0.0.1', self::$server->port, '/upload', [], $body);
    }
}
