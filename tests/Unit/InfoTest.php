<?php

declare(strict_types=1);

use Buggregator\Trap\Info;
use PHPUnit\Framework\TestCase;

final class InfoTest extends TestCase
{
    public function testVersionIsVersion(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', $v1 = Info::version());
        self::assertSame($v1, Info::version());
    }
}
