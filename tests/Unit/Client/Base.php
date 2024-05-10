<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Client;

use Buggregator\Trap\Client\TrapHandle\Counter;
use Buggregator\Trap\Client\TrapHandle\Dumper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\VarDumper\Cloner\Data;
use Symfony\Component\VarDumper\Dumper\DataDumperInterface;

class Base extends TestCase
{
    protected static ?Data $lastData = null;

    protected function setUp(): void
    {
        self::$lastData = null;
        Counter::clear();
        $dumper = $this->getMockBuilder(DataDumperInterface::class)
            ->getMock();
        $dumper->expects($this->any())
            ->method('dump')
            ->with(
                $this->callback(static function (Data $data): bool {
                    static::$lastData = $data;

                    return true;
                })
            )
            ->willReturnArgument(1);

        Dumper::setDumper($dumper);

        parent::setUp();
    }

    protected function tearDown(): void
    {
        self::$lastData = null;
        Counter::clear();
        Dumper::setDumper(null);
        parent::tearDown();
    }
}
