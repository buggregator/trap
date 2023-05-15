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

    public function testPostMultipartFormData(): void
    {
        $file1 = \file_get_contents(__DIR__ . '/../../Stub/deburger.png');
        $file2 = \file_get_contents(__DIR__ . '/../../Stub/buggregator.png');
        $body = <<<BODY
                --Asrf456BGe4h
                Content-Disposition: form-data; name="Authors"

                @roxblnfk and @butschster
                --Asrf456BGe4h
                Content-Disposition: form-data; name="MessageTitle"

                Hello guys! The Buggregator is a great tool!
                --Asrf456BGe4h
                Content-Disposition: form-data; name="MessageText"

                Do you know that Buggregator could be called Deburger? But we decided to name it Buggregator.
                --Asrf456BGe4h
                Content-Disposition: form-data; name="AttachedFile1"; filename="deburger.png"
                Content-Type: image/png

                $file1
                --Asrf456BGe4h
                Content-Disposition: form-data; name="AttachedFile2"; filename="buggregator.png"
                Content-Type: image/png

                $file2
                --Asrf456BGe4h--

                BODY;
        $length = \strlen($body);
        $headers = <<<HTTP
                POST /send-message.html HTTP/1.1
                Host: webmail.example.com
                User-Agent: BrowserForDummies/4.67b
                Content-Type: multipart/form-data; boundary=Asrf456BGe4h
                Content-Length: $length
                Connection: keep-alive
                Keep-Alive: 300\r\n\r\n
                HTTP;

        $generator = $this->makeBodyGenerator($headers . $body);

        $request = (new HttpParser)->parseStream($generator);

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/send-message.html', $request->getUri()->getPath());
        $this->assertSame('1.1', $request->getProtocolVersion());
        $this->assertSame(['webmail.example.com'], $request->getHeader('host'));
        $this->assertNull($request->getParsedBody());
        $this->assertSame('foo=bar&baz=qux&quux=corge+grault', $request->getBody()->__toString());
    }

    public function testGzippedBody(): void
    {
        $http = \file_get_contents(__DIR__ . '/../../Stub/sentry.http');
        $generator = $this->makeBodyGenerator($http);

        $request = (new HttpParser)->parseStream($generator);

        // todo: test body
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
