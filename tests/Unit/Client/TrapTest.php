<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Client;

final class TrapTest extends Base
{
    public function testLabel(): void
    {
        trap(FooName: 'foo-value');
        $this->assertSame('FooName', static::$lastData->getContext()['label']);
    }

    /**
     * Check the first line of dumped stacktrace string contains right file and line.
     */
    public function testStackTrace(): void
    {
        $line = __FILE__ . ':' . __LINE__ and trap()->stackTrace();

        $this->assertArrayHasKey('trace', static::$lastData->getValue());

        $neededLine = \array_key_first(static::$lastData->getValue()['trace']->getValue());

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

        \trap($object, $object);
        unset($object);

        $this->assertNull($ref->get());
    }

    public function testTrapOnce(): void
    {
        foreach ([false, true, true, true, true] as $isNull) {
            \trap(42)->once();
            self::assertSame($isNull, static::$lastData === null);
            static::$lastData = null;
        }
    }

    public static function provideTrapTimes(): iterable
    {
        yield 'no limit' => [0, [false, false, false, false, false]];
        yield 'once' => [1, [false, true, true, true, true, true]];
        yield 'twice' => [2, [false, false, true, true, true]];
        yield 'x' => [10, [false, false, false, false, false, false, false, false, false, false, true, true, true]];
    }

    /**
     * @dataProvider provideTrapTimes
     */
    public function testTrapTimes(int $times, array $sequence): void
    {
        foreach ($sequence as $isNull) {
            \trap(42)->times($times);
            self::assertSame($isNull, static::$lastData === null);
            static::$lastData = null;
        }
    }
}
