<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Support\Stream;

use Buggregator\Trap\Support\StreamHelper;
use Testo\Assert;
use Testo\Test;

#[Test]
final class Base64DecodeFilterTest
{
    public function testFFilterRegistered(): void
    {
        Assert::contains(\stream_get_filters(), \Buggregator\Trap\Support\Stream\Base64DecodeFilter::FILTER_NAME);
    }

    public function testFilterSimpleLine(): void
    {
        $source = 'A test string to encode';

        $encoded = \base64_encode($source);

        $stream = StreamHelper::createFileStream(writeFilters: [\Buggregator\Trap\Support\Stream\Base64DecodeFilter::FILTER_NAME]);

        $stream->write($encoded);

        Assert::same((string) $stream, $source);
    }

    public function testFilterChunked(): void
    {
        $source = 'A test string to encode';

        $encoded = \str_split(\base64_encode($source), 2);

        $stream = StreamHelper::createFileStream(writeFilters: [\Buggregator\Trap\Support\Stream\Base64DecodeFilter::FILTER_NAME]);

        foreach ($encoded as $chunk) {
            $stream->write($chunk);
        }

        Assert::same((string) $stream, $source);
    }
}
