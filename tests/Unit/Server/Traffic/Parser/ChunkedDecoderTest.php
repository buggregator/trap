<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Traffic\Parser;

use Buggregator\Trap\Server\Traffic\Parser\ChunkedDecoder;
use Buggregator\Trap\Server\Traffic\Parser\InvalidRequest;
use Buggregator\Trap\Support\Stream\SizeLimitExceeded;
use Buggregator\Trap\Support\StreamHelper;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Covers(ChunkedDecoder::class)]
#[Test]
final class ChunkedDecoderTest
{
    private const BODY = "4;name=value\r\nWiki\r\n6\r\npedia \r\nE\r\nin \r\n\r\nchunks.\r\n0\r\nExpires: never\r\nX-Sum: 1\r\n\r\n";
    private const PAYLOAD = "Wikipedia in \r\n\r\nchunks.";

    #[DataSet([1])]
    #[DataSet([2])]
    #[DataSet([3])]
    #[DataSet([5])]
    #[DataSet([13])]
    #[DataSet([1024])]
    public function decodesPiecesSplitAtAnyByte(int $pieceSize): void
    {
        $to = StreamHelper::createFileStream();
        $decoder = new ChunkedDecoder($to, 1024);
        $pieces = \str_split(self::BODY, $pieceSize);
        $last = \array_pop($pieces);

        foreach ($pieces as $piece) {
            Assert::false($decoder->write($piece));
        }

        Assert::true($decoder->write($last));
        Assert::same((string) $to, self::PAYLOAD);
    }

    public function acceptsBareLineFeeds(): void
    {
        $to = StreamHelper::createFileStream();

        $done = (new ChunkedDecoder($to, 1024))->write("3\nabc\n0\n\n");

        Assert::true($done);
        Assert::same((string) $to, 'abc');
    }

    public function acceptsUppercaseHexAndWhitespaceBeforeExtension(): void
    {
        $to = StreamHelper::createFileStream();

        $done = (new ChunkedDecoder($to, 1024))->write("0A ; ext\r\n0123456789\r\n0\r\n\r\n");

        Assert::true($done);
        Assert::same((string) $to, '0123456789');
    }

    public function ignoresBytesPastTheEnd(): void
    {
        $to = StreamHelper::createFileStream();

        $done = (new ChunkedDecoder($to, 1024))->write("1\r\na\r\n0\r\n\r\nGET / HTTP/1.1\r\n");

        Assert::true($done);
        Assert::same((string) $to, 'a');
    }

    public function isNotDoneBeforeTheTrailerSectionEnds(): void
    {
        $decoder = new ChunkedDecoder(StreamHelper::createFileStream(), 1024);

        Assert::false($decoder->write("1\r\na\r\n0\r\nX-Sum: 1\r\n"));
    }

    public function acceptsBodyOfExactlyTheLimit(): void
    {
        $to = StreamHelper::createFileStream();

        $done = (new ChunkedDecoder($to, 8))->write("4\r\nabcd\r\n4\r\nefgh\r\n0\r\n\r\n");

        Assert::true($done);
        Assert::same((string) $to, 'abcdefgh');
    }

    public function rejectsChunkGrowingBodyPastLimitBeforeWritingIt(): never
    {
        $to = StreamHelper::createFileStream();
        $decoder = new ChunkedDecoder($to, 8);
        $decoder->write("4\r\nabcd\r\n");

        Expect::exception(SizeLimitExceeded::class)
            ->withMessage('The chunked request body is larger than 8 bytes.');

        try {
            $decoder->write("5\r\n");
        } finally {
            Assert::same((string) $to, 'abcd');
        }
    }

    public function rejectsSizePastIntegerRange(): never
    {
        Expect::exception(SizeLimitExceeded::class);

        (new ChunkedDecoder(StreamHelper::createFileStream(), \PHP_INT_MAX))->write("FFFFFFFFFFFFFFFFFFFF\r\n");
    }

    #[DataSet(["x\r\n"], 'not a number')]
    #[DataSet(["\r\n"], 'empty size')]
    #[DataSet(["-1\r\n"], 'negative size')]
    #[DataSet(["0x1\r\n"], 'hex prefix')]
    #[DataSet(["1\r\nab\r\n"], 'data longer than the size')]
    #[DataSet(["1\r\naXY0\r\n\r\n"], 'no line end after data')]
    public function rejectsBrokenFraming(string $body): never
    {
        Expect::exception(InvalidRequest::class)->withMessage('Invalid chunked body.');

        (new ChunkedDecoder(StreamHelper::createFileStream(), 1024))->write($body);
    }

    /**
     * A line without its end is not buffered without bound.
     */
    #[DataSet([''], 'size line')]
    #[DataSet(["0\r\n"], 'trailer field')]
    public function rejectsEndlessLine(string $prefix): never
    {
        $decoder = new ChunkedDecoder(StreamHelper::createFileStream(), 1024);
        $decoder->write($prefix);

        Expect::exception(InvalidRequest::class);

        for ($i = 0; $i < 100; ++$i) {
            $decoder->write(\str_repeat('1', 1024));
        }
    }

    public function rejectsEndlessTrailerSection(): never
    {
        $decoder = new ChunkedDecoder(StreamHelper::createFileStream(), 1024);
        $decoder->write("0\r\n");

        Expect::exception(InvalidRequest::class);

        for ($i = 0; $i < 10000; ++$i) {
            $decoder->write("X-Trailer: value\r\n");
        }
    }
}
