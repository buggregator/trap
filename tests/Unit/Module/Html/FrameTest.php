<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Html;

use Buggregator\Trap\Module\Html\Frame;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Covers(Frame::class)]
#[Test]
final class FrameTest
{
    public function testRoundTripsThroughString(): void
    {
        $time = new \DateTimeImmutable('2026-01-02 03:04:05');
        $frame = new Frame('Report', PageTest::PAGE, 'shop', $time);

        $restored = Frame::fromString((string) $frame, $time);

        Assert::same($restored->title, 'Report');
        Assert::same($restored->html, PageTest::PAGE);
        Assert::same($restored->project, 'shop');
        Assert::same($restored->type, 'html');
    }

    #[DataSet(['{}'], 'no fields')]
    #[DataSet(['{"title":"x","html":""}'], 'empty markup')]
    #[DataSet(['{"html":"x"}'], 'no title')]
    public function testRejectsOtherJson(string $json): void
    {
        Expect::exception(\InvalidArgumentException::class);

        Frame::fromString($json, new \DateTimeImmutable());
    }
}
