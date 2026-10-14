<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Sender\Frontend\Mapper;

use Buggregator\Trap\Proto\Frame\Http as HttpFrame;
use Buggregator\Trap\Sender\Frontend\Event\AttachedFile;
use Buggregator\Trap\Sender\Frontend\Mapper\HttpRequest;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(HttpRequest::class)]
#[Test]
final class HttpRequestTest
{
    public function testMapsRequestWithRawBody(): void
    {
        $request = (new ServerRequest('PUT', 'http://example.com/api/items?x=1', ['X-Foo' => 'bar'], 'raw payload'))
            ->withQueryParams(['x' => '1'])
            ->withCookieParams(['sid' => 'abc']);
        $time = new \DateTimeImmutable('2024-01-02 03:04:05.123456');

        $event = (new HttpRequest())->map(new HttpFrame($request, $time));

        Assert::same($event->type, 'http-dump');
        Assert::string($event->uuid)->notSame('');
        Assert::same($event->timestamp, (float) $time->format('U.u'));
        Assert::same($event->payload['received_at'], '2024-01-02 03:04:05');
        Assert::same($event->payload['host'], 'example.com');
        Assert::same($event->payload['request']['method'], 'PUT');
        Assert::same($event->payload['request']['uri'], 'api/items');
        Assert::same($event->payload['request']['headers'], $request->getHeaders());
        Assert::same($event->payload['request']['body'], 'raw payload');
        Assert::same($event->payload['request']['query'], ['x' => '1']);
        Assert::same($event->payload['request']['post'], []);
        Assert::same($event->payload['request']['cookies'], ['sid' => 'abc']);
        Assert::same($event->payload['request']['files'], []);
        Assert::count($event->assets, 0);
    }

    public function testParsedBodyReplacesRawBody(): void
    {
        $request = (new ServerRequest('POST', '/form', [], 'a=1&b=2'))
            ->withParsedBody(['a' => '1', 'b' => '2']);

        $event = (new HttpRequest())->map(new HttpFrame($request));

        Assert::same($event->payload['request']['body'], '');
        Assert::same($event->payload['request']['post'], ['a' => '1', 'b' => '2']);
        Assert::same($event->payload['request']['uri'], 'form');
    }

    public function testUploadedFilesBecomeAttachedAssets(): void
    {
        $a = new UploadedFile(Stream::create('aaa'), 3, \UPLOAD_ERR_OK, 'a.txt', 'text/plain');
        $b = new UploadedFile(Stream::create('bbbbb'), 5, \UPLOAD_ERR_OK, 'b.png', 'image/png');
        $request = (new ServerRequest('POST', '/upload'))
            ->withUploadedFiles(['single' => $a, 'many' => [['deep' => $b]]]);

        $event = (new HttpRequest())->map(new HttpFrame($request));

        $assets = \iterator_to_array($event->assets);
        Assert::count($assets, 2);
        foreach ($assets as $key => $asset) {
            Assert::instanceOf($asset, AttachedFile::class);
            Assert::same($key, $asset->uuid);
        }
        [$first, $second] = \array_values($assets);
        Assert::same($first->file, $a);
        Assert::same($second->file, $b);
        Assert::same($event->payload['request']['files'], [
            [
                'id' => $first->uuid,
                'name' => 'a.txt',
                'uri' => $event->uuid . '/' . $first->uuid,
                'size' => 3,
                'mime' => 'text/plain',
            ],
            [
                'id' => $second->uuid,
                'name' => 'b.png',
                'uri' => $event->uuid . '/' . $second->uuid,
                'size' => 5,
                'mime' => 'image/png',
            ],
        ]);
    }
}
