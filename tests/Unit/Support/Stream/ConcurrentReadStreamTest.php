<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Support\Stream;

use Buggregator\Trap\Support\Stream\ConcurrentReadStream;
use Buggregator\Trap\Support\StreamHelper;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\StreamInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Covers(ConcurrentReadStream::class)]
#[Covers(StreamHelper::class)]
final class ConcurrentReadStreamTest
{
    #[Test]
    public function testReadersKeepOwnPositions(): void
    {
        $origin = Stream::create('0123456789');
        $a = StreamHelper::concurrentReadStream($origin);
        $b = StreamHelper::concurrentReadStream($origin);

        Assert::same($a->read(3), '012');
        Assert::same($b->read(2), '01');
        Assert::same($a->read(3), '345');
        Assert::same($b->tell(), 2);
        Assert::same($b->getContents(), '23456789');
        Assert::same($a->getContents(), '6789');
        Assert::true($a->eof());
        Assert::true($b->eof());
    }

    #[Test]
    public function testIgnoresOriginPositionMovedByOthers(): void
    {
        $origin = Stream::create('0123456789');
        $stream = new ConcurrentReadStream($origin);

        Assert::same($stream->read(4), '0123');
        $origin->seek(8);
        Assert::same($stream->read(4), '4567');
    }

    #[Test]
    public function testEofAtExactEndOfKnownSize(): void
    {
        $stream = new ConcurrentReadStream(Stream::create('abcd'));

        Assert::false($stream->eof());
        $stream->read(4);
        Assert::true($stream->eof());

        $stream->rewind();
        Assert::false($stream->eof());
        Assert::same((string) $stream, 'abcd');
    }

    #[Test]
    public function testSeekIsLocal(): void
    {
        $origin = Stream::create('0123456789');
        $a = new ConcurrentReadStream($origin);
        $b = new ConcurrentReadStream($origin);

        $a->seek(-3, \SEEK_END);
        Assert::same($a->tell(), 7);
        Assert::same($b->tell(), 0);
        Assert::same($b->read(2), '01');
        Assert::same($a->read(10), '789');
    }

    #[Test]
    public function testCloseKeepsOriginOpen(): void
    {
        $origin = Stream::create('data');
        $stream = new ConcurrentReadStream($origin);

        $stream->close();

        Assert::true($stream->eof());
        Assert::false($stream->isReadable());
        Assert::same((string) $stream, '');
        Assert::same((string) $origin, 'data');
    }

    #[Test]
    public function testIsReadOnly(): void
    {
        $stream = new ConcurrentReadStream(Stream::create('data'));

        Assert::false($stream->isWritable());
        Expect::exception(\RuntimeException::class);
        $stream->write('more');
    }

    #[Test]
    public function testRejectsNonSeekableStream(): void
    {
        $origin = Stream::create(\fopen('php://output', 'wb'));

        Expect::exception(\InvalidArgumentException::class);
        new ConcurrentReadStream($origin);
    }

    #[Test]
    public function testDetachedStreamHasNoSizeAndMetadata(): void
    {
        $origin = Stream::create('data');
        $stream = new ConcurrentReadStream($origin);
        Assert::same($stream->getSize(), 4);
        Assert::same($stream->getMetadata('seekable'), true);

        Assert::null($stream->detach());

        Assert::null($stream->getSize());
        Assert::same($stream->getMetadata(), []);
        Assert::null($stream->getMetadata('seekable'));
        Assert::false($stream->isSeekable());
        Assert::same((string) $origin, 'data');
    }

    #[Test]
    #[DataSet(['read'], 'read')]
    #[DataSet(['tell'], 'tell')]
    #[DataSet(['seek'], 'seek')]
    #[DataSet(['getContents'], 'get contents')]
    public function testDetachedStreamRefusesToRead(string $method): void
    {
        $stream = new ConcurrentReadStream(Stream::create('data'));
        $stream->detach();

        Expect::exception(\RuntimeException::class)->withMessage('Stream is detached.');
        match ($method) {
            'read' => $stream->read(1),
            'tell' => $stream->tell(),
            'seek' => $stream->seek(1),
            'getContents' => $stream->getContents(),
        };
    }

    #[Test]
    public function testStaysAtStartOfEmptyStream(): void
    {
        $stream = new ConcurrentReadStream(Stream::create(''));

        $read = $stream->read(1);
        $readAt = $stream->tell();
        $contents = $stream->getContents();

        Assert::same([$read, $readAt, $contents, $stream->tell()], ['', 0, '', 0]);
    }

    #[Test]
    public function testEndsAfterWholeContentOfStreamOfUnknownSize(): void
    {
        $origin = \Mockery::mock(StreamInterface::class);
        $origin->allows('isSeekable')->andReturn(true);
        $origin->allows('getSize')->andReturn(null);
        $origin->allows('seek');
        $origin->allows('getContents')->andReturn('data');
        $origin->allows('tell')->andReturn(4);
        $stream = new ConcurrentReadStream($origin);

        $contents = $stream->getContents();

        Assert::same($contents, 'data');
        Assert::true($stream->eof());
    }

    #[Test]
    public function testCastsWholeContentFromAnyPosition(): void
    {
        $stream = new ConcurrentReadStream(Stream::create('0123456789'));
        $stream->read(4);

        Assert::same((string) $stream, '0123456789');
        Assert::true($stream->eof());
    }
}
