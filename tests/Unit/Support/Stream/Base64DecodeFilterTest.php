<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Support\Stream;

use Buggregator\Trap\Support\StreamHelper;
use PHPUnit\Framework\TestCase;

final class Base64DecodeFilterTest extends TestCase
{
    public function testFFilterRegistered(): void
    {
        self::assertContains(\Buggregator\Trap\Support\Stream\Base64DecodeFilter::FILTER_NAME, \stream_get_filters());
    }

    public function testFilterSimpleLine(): void
    {
        $source = 'A test string to encode';

        $encoded = \base64_encode($source);

        $stream = StreamHelper::createFileStream(writeFilters: [\Buggregator\Trap\Support\Stream\Base64DecodeFilter::FILTER_NAME]);

        $stream->write($encoded);

        self::assertSame($source, (string) $stream);
    }

    public function testFilterChunked(): void
    {
        $source = 'A test string to encode';

        $encoded = \str_split(\base64_encode($source), 2);

        $stream = StreamHelper::createFileStream(writeFilters: [\Buggregator\Trap\Support\Stream\Base64DecodeFilter::FILTER_NAME]);

        foreach ($encoded as $chunk) {
            $stream->write($chunk);
        }

        self::assertSame($source, (string) $stream);
    }
}
