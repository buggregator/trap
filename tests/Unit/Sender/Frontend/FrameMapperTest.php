<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Sender\Frontend;

use Buggregator\Trap\Proto\Frame\Otlp\OtlpTraces;
use Buggregator\Trap\Sender\Frontend\FrameMapper;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(FrameMapper::class)]
#[Test]
final class FrameMapperTest
{
    public function testSkipsOtlpTraces(): void
    {
        Assert::null((new FrameMapper())->map(new OtlpTraces([])));
    }
}
