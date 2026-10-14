<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Proto\Frame;

use Buggregator\Trap\Proto\Frame\Http;
use Buggregator\Trap\ProtoType;
use Buggregator\Trap\Support\Json;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Skip;
use Testo\Test;

#[Covers(Http::class)]
#[Test]
final class HttpTest
{
    public function testFrameWithoutFiles(): void
    {
        $request = new ServerRequest('POST', 'http://localhost/foo', [], 'hello');

        $frame = new Http($request);

        Assert::same($frame->type, ProtoType::HTTP);
        Assert::same($frame->getSize(), 5);
        Assert::false($frame->hasFiles());
        Assert::same($frame->getFiles(), []);
        Assert::same(\iterator_to_array($frame->iterateUploadedFiles(), false), []);
        Assert::same((string) $frame->getStream(), 'hello');
    }

    public function testFrameWithNestedFiles(): void
    {
        $a = $this->uploadedFile('aaa', 'a.txt');
        $b = $this->uploadedFile('bbbbb', 'b.txt');
        $c = $this->uploadedFile('cc', 'c.txt');
        $files = ['single' => $a, 'many' => [$b, ['deep' => $c]]];
        $request = (new ServerRequest('POST', '/upload', [], 'body'))->withUploadedFiles($files);

        $frame = new Http($request);

        Assert::true($frame->hasFiles());
        Assert::same($frame->getFiles(), $files);
        Assert::same(\iterator_to_array($frame->iterateUploadedFiles(), false), [$a, $b, $c]);
        Assert::same($frame->getSize(), 4 + 3 + 5 + 2);
    }

    public function testSerializedForm(): void
    {
        $request = (new ServerRequest('PUT', 'http://example.com/path?x=1', ['X-Foo' => 'bar'], 'payload', '2.0', ['REMOTE_ADDR' => '1.2.3.4']))
            ->withCookieParams(['sid' => 'abc'])
            ->withQueryParams(['x' => '1']);

        $data = Json::decode((string) new Http($request));

        Assert::same($data['method'], 'PUT');
        Assert::same($data['uri'], 'http://example.com/path?x=1');
        Assert::same($data['headers']['X-Foo'], ['bar']);
        Assert::same($data['body'], 'payload');
        Assert::same($data['serverParams'], ['REMOTE_ADDR' => '1.2.3.4']);
        Assert::same($data['cookies'], ['sid' => 'abc']);
        Assert::same($data['queryParams'], ['x' => '1']);
        Assert::same($data['protocolVersion'], '2.0');
        Assert::same($data['uploadedFiles'], []);
    }

    public function testStringRoundtrip(): void
    {
        $time = new \DateTimeImmutable('2024-01-02 03:04:05.678901');
        $request = (new ServerRequest('POST', 'http://example.com/a?b=c', ['Content-Type' => 'text/plain'], 'text', '1.0', ['SERVER_PORT' => '80']))
            ->withCookieParams(['k' => 'v'])
            ->withQueryParams(['b' => 'c']);
        $frame = new Http($request, $time);

        $restored = Http::fromString((string) $frame, $time);

        Assert::same((string) $restored, (string) $frame);
        Assert::same($restored->time, $time);
        Assert::same($restored->request->getCookieParams(), ['k' => 'v']);
        Assert::same($restored->request->getQueryParams(), ['b' => 'c']);
        Assert::same($restored->request->getServerParams(), ['SERVER_PORT' => '80']);
        Assert::same($restored->request->getProtocolVersion(), '1.0');
    }

    public function testFromStringAppliesDefaults(): void
    {
        $frame = Http::fromString('{}', new \DateTimeImmutable());

        Assert::same($frame->request->getMethod(), 'GET');
        Assert::same((string) $frame->request->getUri(), '/');
        Assert::same($frame->request->getHeaders(), []);
        Assert::same((string) $frame->getStream(), '');
        Assert::same($frame->request->getProtocolVersion(), '1.1');
        Assert::same($frame->request->getQueryParams(), []);
        Assert::same($frame->request->getCookieParams(), []);
        Assert::false($frame->hasFiles());
        Assert::same($frame->getSize(), 0);
    }

    public function testFromStringBuildsUploadedFiles(): void
    {
        $path = \dirname(__DIR__, 3) . '/Stub/buggregator.png';
        $payload = Json::encode([
            'body' => 'xy',
            'uploadedFiles' => [
                'doc' => [
                    'content' => $path,
                    'size' => 12,
                    'clientFilename' => 'logo.png',
                    'clientMediaType' => 'image/png',
                ],
            ],
        ]);

        $frame = Http::fromString($payload, new \DateTimeImmutable());

        $file = $frame->getFiles()['doc'];
        Assert::instanceOf($file, UploadedFile::class);
        Assert::same((string) $file->getStream(), \file_get_contents($path));
        Assert::same($file->getSize(), 12);
        Assert::same($file->getError(), \UPLOAD_ERR_OK);
        Assert::same($file->getClientFilename(), 'logo.png');
        Assert::same($file->getClientMediaType(), 'image/png');
        Assert::same($frame->getSize(), 2 + 12);
    }

    #[Skip('Bug #233: __toString() serializes UploadedFile objects as {}, so fromString() cannot restore them')]
    public function testStringRoundtripWithFiles(): void
    {
        $request = (new ServerRequest('POST', '/upload'))
            ->withUploadedFiles(['doc' => $this->uploadedFile('abc', 'doc.txt')]);
        $frame = new Http($request);

        $restored = Http::fromString((string) $frame, $frame->time);

        $file = $restored->getFiles()['doc'];
        Assert::same((string) $file->getStream(), 'abc');
        Assert::same($file->getClientFilename(), 'doc.txt');
    }

    private function uploadedFile(string $content, string $name): UploadedFile
    {
        return new UploadedFile(Stream::create($content), \strlen($content), \UPLOAD_ERR_OK, $name, 'text/plain');
    }
}
