<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Client;

use Buggregator\Trap\Client\TrapHandle\Dumper;
use Closure;
use PHPUnit\Framework\TestCase;
use Symfony\Component\VarDumper\Caster\ReflectionCaster;
use Symfony\Component\VarDumper\Cloner\Data;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\DataDumperInterface;

class Base extends TestCase
{
    protected static Data $lastData;
    protected static ?Closure $handler = null;

    protected function setUp(): void
    {
        $cloner = new VarCloner();
        /** @psalm-suppress InvalidArgument */
        $cloner->addCasters(ReflectionCaster::UNSET_CLOSURE_FILE_INFO);
        $dumper = $this->getMockBuilder(DataDumperInterface::class)
            ->getMock();
        $dumper->expects($this->once())
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
        Dumper::setDumper(null);
        parent::tearDown();
    }
}
