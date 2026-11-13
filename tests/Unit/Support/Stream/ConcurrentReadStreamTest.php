<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Support\Stream;

use Buggregator\Trap\Support\Stream\ConcurrentReadStream;
use Buggregator\Trap\Support\StreamHelper;
use Nyholm\Psr7\Stream;
use Testo\Assert;
use Testo\Codecov\Covers;
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
}
