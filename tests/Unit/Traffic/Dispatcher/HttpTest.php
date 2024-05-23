<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Traffic\Dispatcher;

use Buggregator\Trap\Traffic\Dispatcher\Http;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Buggregator\Trap\Traffic\Dispatcher\Http
 */
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

    #[DataProvider('detectProvider')]
    public function testDetect(string $data, ?bool $expected): void
    {
        $dispatcher = new Http();
        $this->assertSame($expected, $dispatcher->detect($data, new \DateTimeImmutable()));
    }
}
