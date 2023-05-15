<?php

namespace Buggregator\Client\Tests\Traffic\Http;

use Buggregator\Client\Traffic\Http\HttpParser;
use Generator;
use PHPUnit\Framework\TestCase;

class HttpParserTest extends TestCase
{
    public function testSimpleGet(): void
    {
        $generator = $this->makeBodyGenerator(<<<HTTP
                GET /foo/bar?get=jet&foo=%20bar+ugar HTTP/1.1
                Host: 127.0.0.1:9912
                User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko/20100101 Firefox/113.0
                Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8
                Accept-Language: ru-RU,ru;q=0.8,en-US;q=0.5,en;q=0.3
                Accept-Encoding: gzip, deflate, br
                DNT: 1
                Connection: keep-alive
                Cookie: koa.sess=Ijo4NjQwMDAwMH0=; koa.sess.sig=liV7oStLo; csrf-token=Gmch9; csrf-token.sig=X0fR
                Upgrade-Insecure-Requests: 1
                Sec-Fetch-Dest: document
                Sec-Fetch-Mode: navigate
                Sec-Fetch-Site: none
                Sec-Fetch-User: ?1

                HTTP);

        $request = (new HttpParser)->parseStream($generator);

        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/foo/bar', $request->getUri()->getPath());
        $this->assertSame('1.1', $request->getProtocolVersion());
        $this->assertSame(['127.0.0.1:9912'], $request->getHeader('host'));
        $this->assertSame(['ru-RU,ru;q=0.8,en-US;q=0.5,en;q=0.3'], $request->getHeader('accept-language'));
    }

    public function testPostUrlEncoded(): void
    {
        $generator = $this->makeBodyGenerator(<<<HTTP
                POST /foo/bar?get=jet&foo=%20bar+ugar HTTP/1.1
                Host: foo.bar.com
                User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko/20100101 Firefox/113.0
                Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8
                Accept-Language: ru-RU,ru;q=0.8,en-US;q=0.5,en;q=0.3
                Accept-Encoding: gzip, deflate, br
                DNT: 1
                Connection: close
                Cookie: koa.sess=Ijo4NjQwMDAwMH0=; koa.sess.sig=liV7oStLo; csrf-token=Gmch9; csrf-token.sig=X0fR
                Upgrade-Insecure-Requests: 1
                Sec-Fetch-Dest: document
                Sec-Fetch-Mode: navigate
                Sec-Fetch-Site: none
                Sec-Fetch-User: ?1

                foo=bar&baz=qux&quux=corge+grault

                HTTP);

        $request = (new HttpParser)->parseStream($generator);

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/foo/bar', $request->getUri()->getPath());
        $this->assertSame('1.1', $request->getProtocolVersion());
        $this->assertSame(['foo.bar.com'], $request->getHeader('host'));
        $this->assertSame(['foo' => 'bar', 'baz' => 'qux', 'quux' => 'corge grault'], $request->getParsedBody());
        $this->assertSame('foo=bar&baz=qux&quux=corge+grault', $request->getBody()->__toString());
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
