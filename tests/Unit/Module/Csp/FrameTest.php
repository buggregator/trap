<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Csp;

use Buggregator\Trap\Module\Csp\Frame;
use Buggregator\Trap\Module\Csp\Report;
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
        [$format, $reports] = Report::parse(null, ReportTest::REPORTS);
        $time = new \DateTimeImmutable('2026-01-02 03:04:05');
        $frame = new Frame($format, $reports, 'shop', $time);

        $restored = Frame::fromString((string) $frame, $time);

        Assert::same($restored->format, Report::FORMAT_REPORTS);
        Assert::same($restored->reports, $reports);
        Assert::same($restored->project, 'shop');
        Assert::same($restored->type, 'csp');
    }

    #[DataSet(['{}'], 'no fields')]
    #[DataSet(['{"format":"json","reports":[{}]}'], 'unknown format')]
    #[DataSet(['{"format":"csp-report","reports":[]}'], 'no reports')]
    public function testRejectsOtherJson(string $json): void
    {
        Expect::exception(\InvalidArgumentException::class);

        Frame::fromString($json, new \DateTimeImmutable());
    }
}
