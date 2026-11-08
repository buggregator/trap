<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Client;

use Buggregator\Trap\Client\TrapHandle\Dumper;
use Symfony\Component\VarDumper\Dumper\DataDumperInterface;
use Testo\Assert;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
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
        Assert::same(self::$lastData->getContext()['label'], 'FooName');
    }

    public function testSimpleContext(): void
    {
        trap('test-value')->context(foo: 'test-context');

        Assert::same(self::$lastData->getContext(), ['foo' => 'test-context']);
    }

    public function testArrayContext(): void
    {
        trap('test-value')->context(['foo' => 'test-context']);

        Assert::same(self::$lastData->getContext(), ['foo' => 'test-context']);
    }

    public function testContextMultiple(): void
    {
        trap('test-value')
            ->context(['foo' => 'test-context'])
            ->context(['bar' => 'bar-context'])
            ->context(foo: 'new');

        Assert::same(self::$lastData->getContext(), ['foo' => 'new', 'bar' => 'bar-context']);
    }

    public function testCodeHighlight(): void
    {
        trap('test-value')->code('php');

        Assert::same(self::$lastData->getContext(), ['language' => 'php']);
    }

    /**
     * Check the first line of dumped stacktrace string contains right file and line.
     */
    public function testStackTrace(): void
    {
        $line = __FILE__ . ':' . __LINE__ and trap()->stackTrace();

        Assert::array(self::$lastData->getValue())->hasKeys('trace');

        $neededLine = \array_key_first(self::$lastData->getValue()['trace']->getValue());

        Assert::string($neededLine)->contains($line);
    }

    /**
     * Nothing is dumped if no arguments are passed to {@see trap()}.
     */
    public function testEmptyTrapCall(): void
    {
        trap();

        Assert::null(self::$lastData);
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

        Assert::null($ref->get());
    }

    public function testTrapOnce(): void
    {
        foreach ([false, true, true, true, true] as $isNull) {
            trap(42)->once();
            Assert::same(self::$lastData === null, $isNull);
            self::$lastData = null;
        }
    }

    public function testReturn(): void
    {
        Assert::same(trap(42)->return(), 42);
        Assert::same(trap(named: 42)->return(), 42);
        Assert::same(trap(named: 42)->return('bad-name'), 42);
        Assert::same(trap(42, 43)->return(), 42);
        Assert::same(trap(int: 42, foo: 'bar')->return('int'), 42);
        Assert::same(trap(int: 42, foo: 'bar')->return(0), 42);
        Assert::same(trap(...['0' => 'foo', 42 => 90])->return(), 'foo');
        Assert::null(trap(null)->return());

        Expect::exception(\InvalidArgumentException::class);
        Assert::same(trap(42, 43)->return(10), 42);
    }

    public function testReturnSendsDumpOnce(): void
    {
        $dumper = \Mockery::mock(DataDumperInterface::class);
        $dumper->shouldReceive('dump')->once()->andReturnNull();
        Dumper::setDumper($dumper);

        Assert::same(trap(42)->return(), 42);
    }

    #[DataProvider('provideTrapTimes')]
    public function testTrapTimes(int $times, array $sequence): void
    {
        foreach ($sequence as $isNull) {
            trap(42)->times($times);
            Assert::same(self::$lastData === null, $isNull);
            self::$lastData = null;
        }
    }
}
