<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Client;

use Buggregator\Trap\Client\TrapHandle\Dumper;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Symfony\Component\VarDumper\Dumper\DataDumperInterface;

final class TrTest extends Base
{
    public function testLabel(): void
    {
        tr(FooName: 'foo-value');
        self::assertSame('FooName', self::$lastData->getContext()['label']);
    }

    /**
     * Check the stacktrace contains three items and it begins with the right function name.
     */
    #[RunInSeparateProcess]
    public function testTrAsStackTrace(): void
    {
        tr();

        // 3 Stack Trace items
        self::assertCount(3, self::$lastData->getValue());
        self::assertStringMatchesFormat('Trace #0  -.---  %fM', self::$lastData->getType());
        $firstLineKey = \array_key_first(self::$lastData->getValue());
        self::assertStringContainsString('tr()', $firstLineKey);

        tr();

        self::assertCount(3, self::$lastData->getValue());
        self::assertStringMatchesFormat('Trace #1  +%fms  %fM', self::$lastData->getType());
        $firstLineKey = \array_key_first(self::$lastData->getValue());
        self::assertStringContainsString('tr()', $firstLineKey);
    }

    /**
     * After calling {@see tr()} the dumped data isn't stored in the memory.
     */
    public function testLeak(): void
    {
        $object = new \stdClass();
        $ref = \WeakReference::create($object);

        tr($object, $object);
        unset($object);

        self::assertNull($ref->get());
    }

    public function testReturn(): void
    {
        self::assertSame(42, tr(42));
        self::assertSame(42, tr(named: 42));
        self::assertSame(42, tr(42, 43));
        self::assertSame('foo', tr(...['0' => 'foo', 42 => 90]));
        self::assertNull(tr(null));
    }

    public function testReturnSendsDumpOnce(): void
    {
        $dumper = $this->getMockBuilder(DataDumperInterface::class)
            ->getMock();
        $dumper->expects(self::once())
            ->method('dump')
            ->willReturnArgument(1);
        Dumper::setDumper($dumper);

        self::assertSame(42, tr(42));
    }
}
