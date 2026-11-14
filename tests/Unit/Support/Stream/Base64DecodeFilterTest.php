<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Support\Stream;

use Buggregator\Trap\Support\Stream\Base64DecodeFilter;
use Buggregator\Trap\Support\StreamHelper;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Data\DataSet;
use Testo\Test;

#[Test]
#[Covers(Base64DecodeFilter::class)]
final class Base64DecodeFilterTest
{
    public static function chunkSizes(): iterable
    {
        foreach ([1, 2, 3, 5, 6, 7, 13, 77] as $size) {
            yield "chunks of $size bytes" => [$size];
        }
    }

    public function testFFilterRegistered(): void
    {
        Assert::contains(\stream_get_filters(), Base64DecodeFilter::FILTER_NAME);
    }

    public function testFilterSimpleLine(): void
    {
        $source = 'A test string to encode';

        $encoded = \base64_encode($source);

        $stream = StreamHelper::createFileStream(writeFilters: [Base64DecodeFilter::FILTER_NAME]);

        $stream->write($encoded);

        Assert::same((string) $stream, $source);
    }

    public function testFilterChunked(): void
    {
        $source = 'A test string to encode';

        $encoded = \str_split(\base64_encode($source), 2);

        $stream = StreamHelper::createFileStream(writeFilters: [Base64DecodeFilter::FILTER_NAME]);

        foreach ($encoded as $chunk) {
            $stream->write($chunk);
        }

        Assert::same((string) $stream, $source);
    }

    public function testFilterPaddingInSeparateChunk(): void
    {
        $stream = StreamHelper::createFileStream(writeFilters: [Base64DecodeFilter::FILTER_NAME]);

        $stream->write('QUJDRA');
        $stream->write('==');

        Assert::same((string) $stream, 'ABCD');
    }

    #[DataSet(['QUJDRA'], 'unpadded')]
    #[DataSet(['QUJDRA=='], 'padded')]
    #[DataSet(["QUJD\r\nRA=="], 'MIME lines')]
    public function testDecodesTheRestWhenReadingEnds(string $encoded): void
    {
        // A memory stream closes the filter by a call of its own, with no data
        $handle = \fopen('php://memory', 'w+b');
        \fwrite($handle, $encoded);
        \rewind($handle);
        \stream_filter_append($handle, Base64DecodeFilter::FILTER_NAME, \STREAM_FILTER_READ);

        $decoded = \stream_get_contents($handle);

        Assert::same($decoded, 'ABCD');
    }

    #[DataSet(['QUJDRA'], 'unpadded')]
    #[DataSet(['QUJDRA=='], 'padded')]
    #[DataSet(["QUJD\r\nRA=="], 'MIME lines')]
    public function testDecodesTheLastDataWithTheClosingCall(string $encoded): void
    {
        // A temp stream passes the last data in the same filter call that closes it
        $stream = StreamHelper::createFileStream(readFilters: [Base64DecodeFilter::FILTER_NAME]);
        $stream->write($encoded);
        $stream->rewind();

        $decoded = $stream->getContents();

        Assert::same($decoded, 'ABCD');
    }

    #[DataProvider('chunkSizes')]
    public function testFilterChunkedAtAnyBoundary(int $size): void
    {
        $source = \random_bytes(1000);
        $stream = StreamHelper::createFileStream(writeFilters: [Base64DecodeFilter::FILTER_NAME]);

        foreach (\str_split(\base64_encode($source), $size) as $chunk) {
            $stream->write($chunk);
        }

        Assert::same((string) $stream, $source);
    }

    #[DataProvider('chunkSizes')]
    public function testFilterMimeLinesChunkedAtAnyBoundary(int $size): void
    {
        $source = \random_bytes(1000);
        $stream = StreamHelper::createFileStream(writeFilters: [Base64DecodeFilter::FILTER_NAME]);

        foreach (\str_split(\chunk_split(\base64_encode($source), 76, "\r\n"), $size) as $chunk) {
            $stream->write($chunk);
        }

        Assert::same((string) $stream, $source);
    }
}
