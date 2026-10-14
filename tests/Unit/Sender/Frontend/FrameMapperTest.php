<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Sender\Frontend;

use Buggregator\Trap\Proto\Frame\Otlp\OtlpTraces;
use Buggregator\Trap\Sender\Frontend\FrameMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FrameMapper::class)]
final class FrameMapperTest extends TestCase
{
    public function testSkipsOtlpTraces(): void
    {
        self::assertNull((new FrameMapper())->map(new OtlpTraces([])));
    }
}
