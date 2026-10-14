<?php

declare(strict_types=1);

use Buggregator\Trap\Info;
use Testo\Assert;
use Testo\Test;

#[Test]
final class InfoTest
{
    public function testVersionIsVersion(): void
    {
        Assert::string($v1 = Info::version())->matchesRegex('/^\d+\.\d+\.\d+/');
        Assert::same(Info::version(), $v1);
    }
}
