<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Support;

use Buggregator\Trap\Support\StreamHelper;
use Buggregator\Trap\Tests\Unit\FiberTrait;
use Nyholm\Psr7\Stream;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(StreamHelper::class)]
final class StreamHelperTest
{
    use FiberTrait;

    private const MB = 1024 * 1024;

    #[Test]
    public function strposCountsFromTheCurrentPosition(): void
    {
        $stream = Stream::create('0123456789--boundary--');
        $stream->seek(4);

        $position = $this->runInFiber(static fn() => StreamHelper::strpos($stream, '--boundary'));

        Assert::same($position, 6);
        Assert::same($stream->tell(), 4);
    }

    #[Test]
    public function strposFindsSubstringAcrossReadChunks(): void
    {
        // The first read takes 1 MB and the length of the needle: the needle starts 3 bytes before its end
        $offset = self::MB + 3;
        $stream = Stream::create(\str_repeat('a', $offset) . 'needle' . \str_repeat('b', 100));

        $position = $this->runInFiber(static fn() => StreamHelper::strpos($stream, 'needle'));

        Assert::same($position, $offset);
        Assert::same($stream->tell(), 0);
    }

    #[Test]
    public function strposFindsSubstringInLaterChunk(): void
    {
        $offset = 2 * self::MB + 100;
        $stream = Stream::create(\str_repeat('a', $offset) . 'needle');

        $position = $this->runInFiber(static fn() => StreamHelper::strpos($stream, 'needle'));

        Assert::same($position, $offset);
    }

    #[Test]
    public function strposReturnsFalseWhenAbsent(): void
    {
        $stream = Stream::create(\str_repeat('a', self::MB + 100));

        $position = $this->runInFiber(static fn() => StreamHelper::strpos($stream, 'needle'));

        Assert::false($position);
        Assert::same($stream->tell(), 0);
    }

    #[Test]
    public function strposReturnsFalseForStreamShorterThanSubstring(): void
    {
        $stream = Stream::create('need');

        Assert::false(StreamHelper::strpos($stream, 'needle'));
    }

    #[Test]
    public function writeStreamUntilStopsBeforeBoundary(): void
    {
        $from = Stream::create('head--boundary--tail');
        $to = Stream::create('');

        $written = $this->runInFiber(static fn() => StreamHelper::writeStreamUntil($from, $to, '--boundary'));

        Assert::same($written, 4);
        Assert::same((string) $to, 'head');
        Assert::same($from->getContents(), '--boundary--tail');
    }

    #[Test]
    public function writeStreamUntilCopiesAllWithoutBoundary(): void
    {
        $from = Stream::create('no boundary here');
        $to = Stream::create('');

        $written = $this->runInFiber(static fn() => StreamHelper::writeStreamUntil($from, $to, '--boundary'));

        Assert::same($written, 16);
        Assert::same((string) $to, 'no boundary here');
    }

    #[Test]
    public function writeStreamCopiesGivenBytesFromCurrentPosition(): void
    {
        $from = Stream::create('0123456789');
        $from->seek(2);
        $to = Stream::create('');

        $this->runInFiber(static fn() => StreamHelper::writeStream($from, $to, 5));

        Assert::same((string) $to, '23456');
        Assert::same($from->tell(), 7);
    }

    #[Test]
    public function writeStreamStopsAtEndOfSource(): void
    {
        $from = Stream::create('short');
        $to = Stream::create('');

        $this->runInFiber(static fn() => StreamHelper::writeStream($from, $to, 100));

        Assert::same((string) $to, 'short');
    }

    #[Test]
    public function writeStreamCopiesLargeBodyInChunks(): void
    {
        $body = \str_repeat('x', 9 * self::MB) . 'end';
        $from = Stream::create($body);
        $to = StreamHelper::createFileStream();

        $this->runInFiber(static fn() => StreamHelper::writeStream($from, $to, \strlen($body)));

        Assert::same($to->getSize(), \strlen($body));
        $to->seek(-3, \SEEK_END);
        Assert::same($to->getContents(), 'end');
    }
}
