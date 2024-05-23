<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Client;

use Buggregator\Trap\Client\TrapHandle\Dumper;
use Symfony\Component\VarDumper\Dumper\DataDumperInterface;

final class TrapTest extends Base
{
    public static function provideTrapTimes(): iterable
    {
        yield 'no limit' => [0, [false, false, false, false, false]];
        yield 'once' => [1, [false, true, true, true, true, true]];
        yield 'twice' => [2, [false, false, true, true, true]];
        yield 'x' => [10, [false, false, false, false, false, false, false, false, false, false, true, true, true]];
    }

    public function testLabel(): void
    {
        trap(FooName: 'foo-value');
        $this->assertSame('FooName', self::$lastData->getContext()['label']);
    }

    public function testSimpleContext(): void
    {
        trap('test-value')->context(foo: 'test-context');

        self::assertSame(['foo' => 'test-context'], self::$lastData->getContext());
    }

    public function testArrayContext(): void
    {
        trap('test-value')->context(['foo' => 'test-context']);

        self::assertSame(['foo' => 'test-context'], self::$lastData->getContext());
    }

    public function testContextMultiple(): void
    {
        trap('test-value')
            ->context(['foo' => 'test-context'])
            ->context(['bar' => 'bar-context'])
            ->context(foo: 'new');

        self::assertSame(['foo' => 'new', 'bar' => 'bar-context'], self::$lastData->getContext());
    }

    /**
     * Check the first line of dumped stacktrace string contains right file and line.
     */
    public function testStackTrace(): void
    {
        $line = __FILE__ . ':' . __LINE__ and trap()->stackTrace();

        $this->assertArrayHasKey('trace', self::$lastData->getValue());

        $neededLine = \array_key_first(self::$lastData->getValue()['trace']->getValue());

        $this->assertStringContainsString($line, $neededLine);
    }

    /**
     * Nothing is dumped if no arguments are passed to {@see trap()}.
     */
    public function testEmptyTrapCall(): void
    {
        trap();

        self::assertNull(self::$lastData);
    }

    /**
     * After calling {@see trap()} the dumped data isn't stored in the memory.
     */
    public function testLeak(): void
    {
        $object = new \stdClass();
        $ref = \WeakReference::create($object);

        trap($object, $object);
        unset($object);

        $this->assertNull($ref->get());
    }

    public function testTrapOnce(): void
    {
        foreach ([false, true, true, true, true] as $isNull) {
            trap(42)->once();
            self::assertSame($isNull, self::$lastData === null);
            self::$lastData = null;
        }
    }

    public function testReturn(): void
    {
        $this->assertSame(42, trap(42)->return());
        $this->assertSame(42, trap(named: 42)->return());
        $this->assertSame(42, trap(named: 42)->return('bad-name'));
        $this->assertSame(42, trap(42, 43)->return());
        $this->assertSame(42, trap(int: 42, foo: 'bar')->return('int'));
        $this->assertSame(42, trap(int: 42, foo: 'bar')->return(0));
        $this->assertSame('foo', trap(...['0' => 'foo', 42 => 90])->return());
        $this->assertNull(trap(null)->return());

        $this->expectException(\InvalidArgumentException::class);
        $this->assertSame(42, trap(42, 43)->return(10));
    }

    public function testReturnSendsDumpOnce(): void
    {
        $dumper = $this->getMockBuilder(DataDumperInterface::class)
            ->getMock();
        $dumper->expects($this->once())
            ->method('dump')
            ->willReturnArgument(1);
        Dumper::setDumper($dumper);

        $this->assertSame(42, trap(42)->return());
    }

    /**
     * @dataProvider provideTrapTimes
     */
    public function testTrapTimes(int $times, array $sequence): void
    {
        foreach ($sequence as $isNull) {
            trap(42)->times($times);
            self::assertSame($isNull, self::$lastData === null);
            self::$lastData = null;
        }
    }
}
