<?php

namespace Buggregator\Trap\Tests\Unit\Traffic\Dispatcher;

use Buggregator\Trap\Traffic\Dispatcher\Http;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HttpTest extends TestCase
{
    public static function detectProvider()
    {
        yield ["GET /foo HTTP/1.1\r\n", true];
        yield ["GET /foo HTTP/1.1\r\nHost: 127.0.0.1:9912\r\nUser-Agent: Mozilla/5.0 (Windows NT 10", true];
        yield ["GET /foo HTTP/1.1\r\nHost: 127.0.0.1:9912\r\n", true,];
        yield ['POST /foo HT', null];
        yield ['GET /foo HTTP/1.1', null];
        yield ["BUGGREGATOR /foo HTTP/1.1\r\n", false];
        yield ["GET  HTTP/1.1\r\n", false];
    }

    #[DataProvider('detectProvider')]
    public function testDetect(string $data, ?bool $expected): void
    {
        $dispatcher = new Http();
        $this->assertSame($expected, $dispatcher->detect($data, new DateTimeImmutable()));
    }
}
