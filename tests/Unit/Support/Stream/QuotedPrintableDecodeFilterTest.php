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
    /**
     * Chunks as written, then the decoded data.
     */
    public static function provideChunks(): iterable
    {
        yield 'split inside an escape after `=`' => [['Gr=', 'C3=BC=C3=9Fe'], 'Grüße'];
        yield 'split inside an escape after its first digit' => [['Gr=C', '3=BC=C3=9Fe'], 'Grüße'];
        yield 'split between `=` and CRLF' => [["soft=", "\r\nbreak"], 'softbreak'];
        yield 'split between CR and LF of a soft line break' => [["soft=\r", "\nbreak"], 'softbreak'];
        yield 'split between `=` and LF' => [["soft=", "\nbreak"], 'softbreak'];
        yield 'whitespace after a soft line break, split inside it' => [["soft= ", "\t\r\nbreak"], 'softbreak'];
        yield 'a hard line break stays' => [["line\r", "\nnext"], "line\r\nnext"];
        yield 'escaped `=` before a split' => [['a=3D', 'b'], 'a=b'];
    }

    public static function chunkSizes(): iterable
    {
        foreach ([1, 2, 3, 5, 7, 13, 77] as $size) {
            yield "chunks of $size bytes" => [$size];
        }
    }

    public function filterIsRegistered(): void
    {
        Assert::contains(\stream_get_filters(), QuotedPrintableDecodeFilter::FILTER_NAME);
    }

    #[DataProvider('provideChunks')]
    public function decodesAcrossChunks(array $chunks, string $decoded): void
    {
        Assert::same(self::write($chunks), $decoded);
    }

    #[DataProvider('chunkSizes')]
    public function decodesChunkedAtAnyBoundary(int $size): void
    {
        $source = \str_repeat("Grüße = Привет, a line long enough for soft line breaks.\r\n", 20);
        $encoded = \quoted_printable_encode($source);

        Assert::same(self::write(\str_split($encoded, $size)), $source);
    }

    #[DataSet(['x = y', 'x = y'], 'a lone `=`')]
    #[DataSet(['a=zz b', 'a=zz b'], 'not a hex escape')]
    #[DataSet(["abc= \r\ndef", 'abcdef'], 'whitespace after a soft line break')]
    public function keepsMalformedData(string $encoded, string $decoded): void
    {
        Assert::same(self::write([$encoded, ' end']), "$decoded end");
    }

    #[DataSet(["a=3D\r\nb=C3=BC"], 'complete')]
    #[DataSet(["a=3D\r\nb=C3=BC="], 'with a trailing soft line break')]
    public function decodesTheLastDataWithTheClosingCall(string $encoded): void
    {
        // A temp stream passes the last data in the same filter call that closes it
        $stream = StreamHelper::createFileStream(readFilters: [QuotedPrintableDecodeFilter::FILTER_NAME]);
        $stream->write($encoded);
        $stream->rewind();

        Assert::same($stream->getContents(), "a=\r\nbü");
    }

    /**
     * @param list<string> $chunks
     */
    private static function write(array $chunks): string
    {
        $stream = StreamHelper::createFileStream(writeFilters: [QuotedPrintableDecodeFilter::FILTER_NAME]);
        foreach ($chunks as $chunk) {
            $stream->write($chunk);
        }

        return (string) $stream;
    }
}
