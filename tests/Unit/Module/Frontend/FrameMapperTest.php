<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Frontend;

use Buggregator\Trap\Module\Frontend\FrameMapper;
use Buggregator\Trap\Proto\Frame\Otlp\OtlpTraces;
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
