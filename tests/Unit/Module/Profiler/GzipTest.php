<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler;

use Buggregator\Trap\Module\Profiler\Gzip;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Covers(Gzip::class)]
#[Test]
final class GzipTest
{
    #[DataSet(["\x1F\x8B\x08\x00", true], 'gzip header')]
    #[DataSet(["\x1F\x8B", true], 'magic only')]
    #[DataSet(["\x1F", false], 'half of magic')]
    #[DataSet(["\x8B\x1F\x08\x00", false], 'magic reversed')]
    #[DataSet(['', false], 'empty')]
    #[DataSet(["main;foo 1\n\x1F\x8B", false], 'magic not at start')]
    public function testTellsGzipByMagic(string $content, bool $compressed): void
    {
        Assert::same(Gzip::isCompressed($content), $compressed);
    }

    public function testInflatesUpToLimitInclusive(): void
    {
        $content = \str_repeat('x', 16);

        Assert::same(Gzip::inflate(\gzencode($content), 16), $content);
    }

    public function testRejectsInflatingPastLimit(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('The profile decompresses to more than 16 bytes.');

        Gzip::inflate(\gzencode(\str_repeat('x', 17)), 16);
    }

    #[DataSet(["\x1F\x8B\x08\x00broken"], 'broken stream')]
    #[DataSet(["\x1F\x8B"], 'magic only')]
    public function testRejectsInvalidGzip(string $content): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('Invalid gzip data.');

        Gzip::inflate($content, 1024);
    }

    public function testRejectsTruncatedGzip(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('Invalid gzip data.');

        Gzip::inflate(\substr(\gzencode(\str_repeat('abc', 1000)), 0, 20), 100_000);
    }

    public function testRaisesNoPhpWarningOnInvalidGzip(): void
    {
        \error_clear_last();

        try {
            Gzip::inflate("\x1F\x8B\x08\x00broken", 1024);
        } catch (\InvalidArgumentException) {
        }

        Assert::null(\error_get_last());
    }

    public function testRestoresErrorHandlerAfterFailure(): void
    {
        $handler = static fn(): bool => false;
        \set_error_handler($handler);
        try {
            try {
                Gzip::inflate("\x1F\x8B\x08\x00broken", 1024);
            } catch (\InvalidArgumentException) {
            }
            $current = \set_error_handler(null);
        } finally {
            \restore_error_handler();
            \restore_error_handler();
        }

        Assert::same($current, $handler);
    }
}
