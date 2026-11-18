<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Traffic\Parser;

use Buggregator\Trap\Server\Traffic\Parser;
use Buggregator\Trap\Test\Mock\StreamClientMock;
use Buggregator\Trap\Tests\Unit\FiberTrait;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Testo\Assert;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

final class HttpParserTest
{
    use FiberTrait;

    #[Test]
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

        Assert::same($request->getMethod(), 'GET');
        Assert::same($request->getUri()->getPath(), '/foo/bar');
        Assert::same($request->getProtocolVersion(), '1.1');
        Assert::same($request->getHeader('host'), ['127.0.0.1:9912']);
        Assert::same($request->getHeader('accept-language'), ['ru-RU,ru;q=0.8,en-US;q=0.5,en;q=0.3']);
        Assert::same($request->getCookieParams(), [
            'koa.sess' => 'Ijo4NjQwMDAwMH0=',
            'koa.sess.sig' => 'liV7oStLo',
            'csrf-token' => 'Gmch9',
            'csrf-token.sig' => 'X0fR',
        ]);
    }

    #[Test]
    public function testPathStartingWithSlashesIsNotAuthority(): void
    {
        $request = $this->parseStream(["GET //mcp//x?a=b HTTP/1.1\r\nHost: 127.0.0.1:9912\r\n\r\n"]);

        Assert::same($request->getUri()->getPath(), '/mcp//x');
        Assert::same($request->getUri()->getQuery(), 'a=b');
        Assert::same($request->getUri()->getHost(), '');
    }

    #[Test]
    #[DataSet(['/api/user:42', '/api/user:42', ''])]
    #[DataSet(['/api/user:42/x', '/api/user:42/x', ''])]
    #[DataSet(['/logs/12:30', '/logs/12:30', ''])]
    #[DataSet(['/a:0', '/a:0', ''])]
    #[DataSet(['/media/v1:9000', '/media/v1:9000', ''])]
    #[DataSet(['/:80', '/:80', ''])]
    #[DataSet(['/p:1/q?a=b:2', '/p:1/q', 'a=b:2'])]
    public function testOriginFormTargetWithColonIsPath(string $target, string $path, string $query): void
    {
        $request = $this->parseStream(["GET {$target} HTTP/1.1\r\nHost: 127.0.0.1:9912\r\n\r\n"]);

        Assert::same($request->getUri()->getPath(), $path);
        Assert::same($request->getUri()->getQuery(), $query);
        Assert::same($request->getUri()->getHost(), '');
        Assert::null($request->getUri()->getPort());
    }

    /**
     * Routing of the modules and the HTTP dump read these parts of the URI.
     */
    #[Test]
    #[DataSet(['/', '/', '/', ''])]
    #[DataSet(['/api/1/store/', '/api/1/store/', '/api/1/store/', ''])]
    #[DataSet(['/v1/traces', '/v1/traces', '/v1/traces', ''])]
    #[DataSet(['/api/profiler/store', '/api/profiler/store', '/api/profiler/store', ''])]
    #[DataSet(['/x?a=1&b=%20', '/x?a=1&b=%20', '/x', 'a=1&b=%20'])]
    #[DataSet(['/a+b', '/a+b', '/a+b', ''])]
    #[DataSet(['/%7Euser', '/%7Euser', '/%7Euser', ''])]
    #[DataSet(['/path;param', '/path;param', '/path;param', ''])]
    #[DataSet(['/a|c?q=[1]', '/a%7Cc?q=%5B1%5D', '/a%7Cc', 'q=%5B1%5D'])]
    #[DataSet(['/a?b#c', '/a?b#c', '/a', 'b'])]
    public function testOriginFormTargetUri(string $target, string $uri, string $path, string $query): void
    {
        $request = $this->parseStream(["GET {$target} HTTP/1.1\r\nHost: 127.0.0.1:9912\r\n\r\n"]);

        Assert::same((string) $request->getUri(), $uri);
        Assert::same($request->getUri()->getPath(), $path);
        Assert::same($request->getUri()->getQuery(), $query);
    }

    #[Test]
    public function testIpv6AbsoluteFormTarget(): void
    {
        $request = $this->parseStream(["GET http://[::1]:9912/x?a=b HTTP/1.1\r\nHost: [::1]:9912\r\n\r\n"]);

        Assert::same($request->getUri()->getHost(), '[::1]');
        Assert::same($request->getUri()->getPort(), 9912);
        Assert::same($request->getUri()->getPath(), '/x');
    }

    #[Test]
    #[DataSet(["Bad Header: v\r\n"], 'space in name')]
    #[DataSet(["X-Key: a\x01b\r\n"], 'control character in value')]
    public function testInvalidHeaderIsNotQuotedInException(string $header): never
    {
        Expect::exception(Parser\InvalidRequest::class)->withMessage('Invalid header.');

        $this->parseStream(["GET / HTTP/1.1\r\nHost: 127.0.0.1:9912\r\n{$header}\r\n"]);
    }

    #[Test]
    public function testAbsoluteFormTargetKeepsAuthority(): void
    {
        $request = $this->parseStream(["GET http://u:p@example.test:81/x?a=b HTTP/1.1\r\nHost: example.test\r\n\r\n"]);

        Assert::same($request->getUri()->getHost(), 'example.test');
        Assert::same($request->getUri()->getUserInfo(), 'u:p');
        Assert::same($request->getUri()->getPath(), '/x');
        Assert::same($request->getUri()->getQuery(), 'a=b');
    }

    #[Test]
    public function testAsteriskFormTarget(): void
    {
        $request = $this->parseStream(["OPTIONS * HTTP/1.1\r\nHost: 127.0.0.1:9912\r\n\r\n"]);

        Assert::same($request->getMethod(), 'OPTIONS');
        Assert::same($request->getRequestTarget(), '*');
    }

    #[Test]
    public function testUnparsableTargetIsNotQuotedInException(): never
    {
        Expect::exception(Parser\InvalidRequest::class)->withMessage('Invalid request target.');

        $this->parseStream(["GET http:///s3cr3t HTTP/1.1\r\nHost: 127.0.0.1:9912\r\n\r\n"]);
    }

    /*\
     * Parer doesn't fail on wrong cookies
     */
    #[Test]
    public function testWrongCookie(): void
    {
        $body = \str_split(
            <<<HTTP
                GET /foo/bar?get=jet&foo=%20bar+ugar HTTP/1.1\r
                Host: 127.0.0.1:9912\r
                User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko/20100101 Firefox/113.0\r
                Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8\r
                Accept-Language: ru-RU,ru;q=0.8,en-US;q=0.5,en;q=0.3\r
                Accept-Encoding: gzip, deflate, br\r
                Connection: keep-alive\r
                Cookie: koa.sess; csrf-token.sig=X0fR\r
                Upgrade-Insecure-Requests: 1\r
                Sec-Fetch-User: ?1\r\n\r\n
                HTTP,
            50,
        );

        $request = $this->parseStream($body);

        Assert::same($request->getMethod(), 'GET');
        Assert::same($request->getUri()->getPath(), '/foo/bar');
        Assert::same($request->getProtocolVersion(), '1.1');
        Assert::same($request->getHeader('host'), ['127.0.0.1:9912']);
        Assert::same($request->getHeader('accept-language'), ['ru-RU,ru;q=0.8,en-US;q=0.5,en;q=0.3']);
        Assert::same($request->getCookieParams(), [
            'csrf-token.sig' => 'X0fR',
        ]);
    }

    #[Test]
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
                Content-Length: 33\r
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

        Assert::same($request->getMethod(), 'POST');
        Assert::same($request->getUri()->getPath(), '/foo/bar');
        Assert::same($request->getProtocolVersion(), '1.1');
        Assert::same($request->getHeader('host'), ['foo.bar.com']);
        Assert::same($request->getBody()->__toString(), 'foo=bar&baz=qux&quux=corge+grault');
        Assert::same($request->getParsedBody(), ['foo' => 'bar', 'baz' => 'qux', 'quux' => 'corge grault']);
    }

    /**
     * The next pipelined request stays in the stream: a body read past the request would consume it
     * or wait for bytes that never come.
     */
    #[Test]
    #[DataSet(['POST', "Content-Length: 0\r\n"], 'POST with zero length')]
    #[DataSet(['PUT', "Content-Length: 0\r\n"], 'PUT with zero length')]
    #[DataSet(['DELETE', "Content-Length: 0\r\n"], 'DELETE with zero length')]
    #[DataSet(['POST', ''], 'POST without length')]
    #[DataSet(['DELETE', ''], 'DELETE without length')]
    public function testEmptyBodyIsNotReadFromStream(string $method, string $lengthHeader): void
    {
        $next = "GET /next HTTP/1.1\r\nHost: 127.0.0.1:9912\r\n\r\n";
        $stream = StreamClientMock::createFromGenerator(
            (static function () use ($method, $lengthHeader, $next) {
                yield "{$method} /x HTTP/1.1\r\nHost: 127.0.0.1:9912\r\n{$lengthHeader}\r\n{$next}";
            })(),
        );

        $request = $this->runInFiber(static fn() => (new Parser\Http())->parseStream($stream));

        Assert::same($request->getBody()->__toString(), '');
        Assert::same($stream->getData(), $next);
    }

    #[Test]
    public function testPostMultipartFormData(): void
    {
        $file1 = \file_get_contents(__DIR__ . '/../../../../Stub/deburger.png');
        $file2 = \file_get_contents(__DIR__ . '/../../../../Stub/buggregator.png');
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

        Assert::same($request->getMethod(), 'POST');
        Assert::same($request->getUri()->getPath(), '/send-message.html');
        Assert::same($request->getProtocolVersion(), '1.1');
        Assert::same($request->getHeader('host'), ['webmail.example.com']);
        // POST data
        Assert::equals($request->getParsedBody(), [
            'Authors' => '@roxblnfk and @butschster',
            'MessageTitle' => 'Hello guys! The Buggregator is a great tool!',
            'MessageText' => 'Do you know that Buggregator could be called Deburger? But we decided to name it Buggregator.',
        ]);
        // Uploaded files
        Assert::count($files = $request->getUploadedFiles(), 2);
        /** @var UploadedFileInterface[] $files */
        Assert::same($files['AttachedFile1'][0]->getClientFilename(), 'deburger.png');
        Assert::same($files['AttachedFile1'][0]->getClientMediaType(), 'image/png');
        Assert::same($files['AttachedFile1'][0]->getStream()->__toString(), $file1);

        Assert::same($files['AttachedFile2'][0]->getClientFilename(), 'buggregator.png');
        Assert::same($files['AttachedFile2'][0]->getClientMediaType(), 'image/png');
        Assert::same($files['AttachedFile2'][0]->getStream()->__toString(), $file2);
    }

    #[Test]
    public function testGzippedBody(): void
    {
        $http = \file_get_contents(__DIR__ . '/../../../../Stub/sentry.bin');

        $request = $this->parseStream($http);

        $file = \file_get_contents(__DIR__ . '/../../../../Stub/sentry-body.bin');
        Assert::same($request->getBody()->__toString(), $file);
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
            })(),
        );
        return $this->runInFiber(static fn() => (new Parser\Http())->parseStream($stream));
    }
}
