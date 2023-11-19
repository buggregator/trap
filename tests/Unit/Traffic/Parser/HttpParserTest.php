<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Traffic\Parser;

use Buggregator\Trap\Test\Mock\StreamClientMock;
use Buggregator\Trap\Tests\Unit\FiberTrait;
use Buggregator\Trap\Traffic\Parser;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

class HttpParserTest extends TestCase
{
    use FiberTrait;

    public function testSimpleGet(): void
    {
        $body = \str_split(
            <<<HTTP
                GET /foo/bar?get=jet&foo=%20bar+ugar HTTP/1.1\r
                Host: 127.0.0.1:9912\r
                User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko/20100101 Firefox/113.0\r
                Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8\r
                Accept-Language: ru-RU,ru;q=0.8,en-US;q=0.5,en;q=0.3\r
                Accept-Encoding: gzip, deflate, br\r
                DNT: 1\r
                Connection: keep-alive\r
                Cookie: koa.sess=Ijo4NjQwMDAwMH0=; koa.sess.sig=liV7oStLo; csrf-token=Gmch9; csrf-token.sig=X0fR\r
                Upgrade-Insecure-Requests: 1\r
                Sec-Fetch-Dest: document\r
                Sec-Fetch-Mode: navigate\r
                Sec-Fetch-Site: none\r
                Sec-Fetch-User: ?1\r\n\r\n
                HTTP,
            50,
        );

        $request = $this->parseStream($body);

        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('/foo/bar', $request->getUri()->getPath());
        $this->assertSame('1.1', $request->getProtocolVersion());
        $this->assertSame(['127.0.0.1:9912'], $request->getHeader('host'));
        $this->assertSame(['ru-RU,ru;q=0.8,en-US;q=0.5,en;q=0.3'], $request->getHeader('accept-language'));
        $this->assertSame([
            'koa.sess' => 'Ijo4NjQwMDAwMH0=',
            'koa.sess.sig' => 'liV7oStLo',
            'csrf-token' => 'Gmch9',
            'csrf-token.sig' => 'X0fR',
        ], $request->getCookieParams());
    }

    public function testPostUrlEncoded(): void
    {
        $body = \str_split(
            <<<HTTP
                POST /foo/bar?get=jet&foo=%20bar+ugar HTTP/1.1\r
                Host: foo.bar.com\r
                User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko/20100101 Firefox/113.0\r
                Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8\r
                Accept-Language: ru-RU,ru;q=0.8,en-US;q=0.5,en;q=0.3\r
                Accept-Encoding: gzip, deflate, br\r
                DNT: 1\r
                Content-Type: application/x-www-form-urlencoded\r
                Connection: close\r
                Cookie: koa.sess=Ijo4NjQwMDAwMH0=; koa.sess.sig=liV7oStLo; csrf-token=Gmch9; csrf-token.sig=X0fR\r
                Upgrade-Insecure-Requests: 1\r
                Sec-Fetch-Dest: document\r
                Sec-Fetch-Mode: navigate\r
                Sec-Fetch-Site: none\r
                Sec-Fetch-User: ?1\r\n\r
                foo=bar&baz=qux&quux=corge+grault\r\n\r\n
                HTTP,
            50,
        );

        $request = $this->parseStream($body);

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/foo/bar', $request->getUri()->getPath());
        $this->assertSame('1.1', $request->getProtocolVersion());
        $this->assertSame(['foo.bar.com'], $request->getHeader('host'));
        $this->assertSame('foo=bar&baz=qux&quux=corge+grault', $request->getBody()->__toString());
        $this->assertSame(['foo' => 'bar', 'baz' => 'qux', 'quux' => 'corge grault'], $request->getParsedBody());
    }

    public function testPostMultipartFormData(): void
    {
        $file1 = \file_get_contents(__DIR__ . '/../../../Stub/deburger.png');
        $file2 = \file_get_contents(__DIR__ . '/../../../Stub/buggregator.png');
        $body = <<<BODY
                --Asrf456BGe4h\r
                Content-Disposition: form-data; name="Authors"\r
                \r
                @roxblnfk and @butschster\r
                --Asrf456BGe4h\r
                Content-Disposition: form-data; name="MessageTitle"\r
                \r
                Hello guys! The Buggregator is a great tool!\r
                --Asrf456BGe4h\r
                Content-Disposition: form-data; name="MessageText"\r
                \r
                Do you know that Buggregator could be called Deburger? But we decided to name it Buggregator.\r
                --Asrf456BGe4h\r
                Content-Disposition: form-data; name="AttachedFile1"; filename="deburger.png"\r
                Content-Type: image/png\r
                \r
                $file1\r
                --Asrf456BGe4h\r
                Content-Disposition: form-data; name="AttachedFile2"; filename="buggregator.png"\r
                Content-Type: image/png\r
                \r
                $file2\r
                --Asrf456BGe4h--\r\n\r\n
                BODY;
        $length = \strlen($body);
        $headers = <<<HTTP
                POST /send-message.html HTTP/1.1\r
                Host: webmail.example.com\r
                User-Agent: BrowserForDummies/4.67b\r
                Content-Type: multipart/form-data; boundary=Asrf456BGe4h\r
                Content-Length: $length\r
                Connection: keep-alive\r
                Keep-Alive: 300\r\n\r\n
                HTTP;

        $request = $this->parseStream($headers . $body);

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/send-message.html', $request->getUri()->getPath());
        $this->assertSame('1.1', $request->getProtocolVersion());
        $this->assertSame(['webmail.example.com'], $request->getHeader('host'));
        // POST data
        $this->assertEquals([
            'Authors' => '@roxblnfk and @butschster',
            'MessageTitle' => 'Hello guys! The Buggregator is a great tool!',
            'MessageText' => 'Do you know that Buggregator could be called Deburger? But we decided to name it Buggregator.',
        ], $request->getParsedBody());
        // Uploaded files
        $this->assertCount(2, $files = $request->getUploadedFiles());
        /** @var UploadedFileInterface[] $files */
        $this->assertSame('deburger.png', $files['AttachedFile1'][0]->getClientFilename());
        $this->assertSame('image/png', $files['AttachedFile1'][0]->getClientMediaType());
        $this->assertSame($file1, $files['AttachedFile1'][0]->getStream()->__toString());

        $this->assertSame('buggregator.png', $files['AttachedFile2'][0]->getClientFilename());
        $this->assertSame('image/png', $files['AttachedFile2'][0]->getClientMediaType());
        $this->assertSame($file2, $files['AttachedFile2'][0]->getStream()->__toString());
    }

    public function testGzippedBody(): void
    {
        $http = \file_get_contents(__DIR__ . '/../../../Stub/sentry.bin');

        $request = $this->parseStream($http);

        $file = \file_get_contents(__DIR__ . '/../../../Stub/sentry-body.bin');
        self::assertSame($file, $request->getBody()->__toString());
    }

    private function parseStream(array|string $body): ServerRequestInterface
    {
        $stream = StreamClientMock::createFromGenerator(
            (static function () use ($body) {
                if (\is_string($body)) {
                    yield $body;
                    return;
                }
                yield from $body;
            })()
        );
        return $this->runInFiber(static fn() => (new Parser\Http)->parseStream($stream));
    }
}
