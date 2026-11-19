<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Support\Stream;

use Buggregator\Trap\Support\Stream\QuotedPrintableDecodeFilter;
use Buggregator\Trap\Support\StreamHelper;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Data\DataSet;
use Testo\Test;

#[Test]
#[Covers(QuotedPrintableDecodeFilter::class)]
final class QuotedPrintableDecodeFilterTest
{
    private const ENCODED = "a=3Db caf=C3=a9 soft=\r\nbreak\r\nLF soft=\nbreak, malformed a=ZZb and price = 5. End=3D";

    public static function chunkSizes(): iterable
    {
        foreach ([1, 2, 3, 4, 5, 7, 13, 1000] as $size) {
            yield "chunks of $size bytes" => [$size];
        }
    }

    public function filterIsRegistered(): void
    {
        Assert::contains(\stream_get_filters(), QuotedPrintableDecodeFilter::FILTER_NAME);
    }

    #[DataProvider('chunkSizes')]
    public function decodesChunkedAtAnyBoundary(int $size): void
    {
        $stream = StreamHelper::createFileStream(writeFilters: [QuotedPrintableDecodeFilter::FILTER_NAME]);

        foreach (\str_split(self::ENCODED, $size) as $chunk) {
            $stream->write($chunk);
        }

        Assert::same((string) $stream, \quoted_printable_decode(self::ENCODED));
    }

    #[DataSet(['x=3D'], 'escape')]
    #[DataSet(['x='], 'soft line break without a line end')]
    #[DataSet(['x=3'], 'cut escape')]
    public function decodesTheLastDataWithTheClosingCall(string $encoded): void
    {
        // A temp stream passes the last data in the same filter call that closes it
        $stream = StreamHelper::createFileStream(readFilters: [QuotedPrintableDecodeFilter::FILTER_NAME]);
        $stream->write($encoded);
        $stream->rewind();

        Assert::same($stream->getContents(), \quoted_printable_decode($encoded));
    }
}
