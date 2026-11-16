<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Event;

use Buggregator\Trap\Module\Tracing\Frame\OtlpTraces;
use Buggregator\Trap\Server\Event\FrameMapper;
use Internal\Container\ObjectContainer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(FrameMapper::class)]
#[Test]
final class FrameMapperTest
{
    public function testSkipsOtlpTraces(): void
    {
        Assert::null(((new ObjectContainer())->get(FrameMapper::class))->map(new OtlpTraces([])));
    }
}
