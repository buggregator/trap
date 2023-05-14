<?php

namespace Buggregator\Client\Tests\Traffic\Http;

use Buggregator\Client\Traffic\Http\HttpParser;
use Generator;
use PHPUnit\Framework\TestCase;

class HttpParserTest extends TestCase
{
    public function testParseStream(): void
    {
        $generator = $this->makeBodyGenerator(<<<HTTP
                GET /foo/bar?get=jet&foo=%20bar+ugar HTTP/1.1
                Host: 127.0.0.1:9912
                User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko
                /20100101 Firefox/113.0
                Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/a
                vif,image/webp,*/*;q=0.8
                Accept-Language: ru-RU,ru;q=0.8,en-US;q=0.5,en;q=0.3
                Accept-Encoding: gzip, deflate, br
                DNT: 1
                Connection: keep-alive
                Cookie: koa.sess=eyJzZWNyZXQiOiJoS3F1R1hZNlhKb2szaUlvejVNOTN5d04iLCJf
                ZXhwaXJlIjoxNjgyNTg1NTQyMTU1LCJfbWF4QWdlIjo4NjQwMDAwMH0=; koa.sess.si
                g=lJfOIaUsAnRD6Y4IN-PiV7oStLo; csrf-token=Gmch9GSd-asI-vnwWCNg3lrcWWl
                CmbTMuNpM; csrf-token.sig=X0fRqFoUW0zDsmBe6WVe-dHrbFQ
                Upgrade-Insecure-Requests: 1
                Sec-Fetch-Dest: document
                Sec-Fetch-Mode: navigate
                Sec-Fetch-Site: none
                Sec-Fetch-User: ?1

                HTTP);

        $request = HttpParser::parseStream($generator);

        $this->assertSame('GET', $request->method);
        $this->assertSame('/foo/bar?get=jet&foo=%20bar+ugar', $request->uri);
        $this->assertSame('HTTP/1.1', $request->protocol);
        $this->assertSame(['127.0.0.1:9912'], $request->headers['host']);
        $this->assertSame(['ru-RU,ru;q=0.8,en-US;q=0.5,en;q=0.3'], $request->headers['accept-language']);
    }

    /**
     * @return Generator<int, string, mixed, void>
     */
    private function makeBodyGenerator(string $data): Generator
    {
        foreach (\explode("\n", $data) as $line) {
            yield \rtrim($line, "\r") ."\r\n";
        }
    }
}
