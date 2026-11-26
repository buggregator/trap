<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Support;

use Buggregator\Trap\Support\Hexdump;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(Hexdump::class)]
#[Test]
final class HexdumpTest
{
    public function testPrintsAsHexdumpC(): void
    {
        Assert::same(
            Hexdump::text("\x00\x01binary data <b>\xff\n!"),
            "00000000  00 01 62 69 6e 61 72 79  20 64 61 74 61 20 3c 62  |..binary data <b|\n"
            . "00000010  3e ff 0a 21                                       |>..!|\n",
        );
    }

    public function testOffsetOfFirstByte(): void
    {
        Assert::same(Hexdump::rows('A', 0x20), [[0x20, '41' . \str_repeat(' ', 46), 'A']]);
    }

    public function testNoBytesNoRows(): void
    {
        Assert::same(Hexdump::text(''), '');
    }
}
