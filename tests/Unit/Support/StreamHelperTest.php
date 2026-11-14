<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Support;

use Buggregator\Trap\Support\Activity;
use Buggregator\Trap\Support\Stream\Base64DecodeFilter;
use Buggregator\Trap\Support\StreamHelper;
use Buggregator\Trap\Tests\Unit\FiberTrait;
use Buggregator\Trap\Tests\Unit\Support\Stub\ChunkedStream;
use Nyholm\Psr7\ServerRequest;
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

    #[Test]
    public function strposFindsSubstringOfTheWholeStream(): void
    {
        Assert::same(StreamHelper::strpos(Stream::create('needle'), 'needle'), 0);
    }

    #[Test]
    public function strposFindsTheFirstOccurrence(): void
    {
        $stream = Stream::create('needle' . \str_repeat('a', 2 * self::MB) . 'needle');

        $position = $this->runInFiber(static fn() => StreamHelper::strpos($stream, 'needle'));

        Assert::same($position, 0);
    }

    #[Test]
    public function strposReportsActivityOnEachChunk(): void
    {
        $stream = Stream::create(\str_repeat('a', 2 * self::MB + 100) . 'needle');

        [$position, $activity] = self::suspensions(static fn() => StreamHelper::strpos($stream, 'needle'));

        Assert::same($position, 2 * self::MB + 100);
        Assert::int(\count($activity))->greaterThan(1);
        Assert::same(\array_unique($activity), [true]);
    }

    #[Test]
    public function writeStreamUntilCountsBytesOfEveryChunk(): void
    {
        $from = new ChunkedStream('abcdefghijklmn--btail', chunk: 6);
        $to = Stream::create('');

        [$written, $activity] = self::suspensions(static fn() => StreamHelper::writeStreamUntil($from, $to, '--b'));

        Assert::same($written, 14);
        Assert::same((string) $to, 'abcdefghijklmn');
        Assert::same($from->getContents(), '--btail');
        Assert::same($activity, [true, true, true]);
    }

    #[Test]
    public function writeStreamCountsBytesOfEveryChunk(): void
    {
        $from = new ChunkedStream('0123456789', chunk: 4);
        $to = Stream::create('');

        [, $activity] = self::suspensions(static fn() => StreamHelper::writeStream($from, $to, 6));

        Assert::same((string) $to, '012345');
        Assert::same($from->reads, [6, 2]);
        Assert::same($activity, [true, true]);
    }

    #[Test]
    public function unzipBodyReportsActivityOnEachChunk(): void
    {
        $body = \random_bytes(40 * 1024);
        $request = (new ServerRequest('POST', '/'))->withBody(Stream::create((string) \gzencode($body)));

        [$unzipped, $activity] = self::suspensions(static fn() => StreamHelper::unzipBody($request));

        Assert::same((string) $unzipped->getBody(), $body);
        Assert::int(\count($activity))->greaterThan(1);
        Assert::same(\array_unique($activity), [true]);
    }

    #[Test]
    public function createFileStreamKeepsUpTo4MbInMemory(): void
    {
        $stream = StreamHelper::createFileStream();

        Assert::same($stream->getMetadata('uri'), 'php://temp/maxmemory:' . 4 * self::MB);
    }

    #[Test]
    public function createFileStreamAppliesReadFiltersInOrderOnReading(): void
    {
        // Decoding before upper-casing differs from the other order; PHP 8.6 can't seek a stream with `convert.*` filters
        $stream = StreamHelper::createFileStream(readFilters: [Base64DecodeFilter::FILTER_NAME, 'string.toupper' => null]);
        $stream->write('YWJj');
        $stream->rewind();

        Assert::same($stream->getSize(), 4);
        Assert::same($stream->getContents(), 'ABC');
    }

    /**
     * Runs the callback in a fiber and tells, at each suspension, whether it reported {@see Activity}.
     *
     * @template T
     *
     * @param \Closure(): T $callback
     *
     * @return array{T, list<bool>}
     */
    private static function suspensions(\Closure $callback): array
    {
        Activity::take();
        $activity = [];
        $fiber = new \Fiber($callback);
        $fiber->start();
        while (!$fiber->isTerminated()) {
            $activity[] = Activity::take();
            $fiber->resume();
        }

        return [$fiber->getReturn(), $activity];
    }
}
