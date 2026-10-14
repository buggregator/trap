<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Client;

use Buggregator\Trap\Client\TrapHandle\Dumper;
use Symfony\Component\VarDumper\Dumper\DataDumperInterface;
use Testo\Assert;
use Testo\Test;

#[Test]
final class TrTest extends Base
{
    /** The `%f` placeholder of PHPUnit's `assertStringMatchesFormat()`. */
    private const FLOAT = '[+-]?\.?\d+\.?\d*(?:[Ee][+-]?\d+)?';

    public function testLabel(): void
    {
        tr(FooName: 'foo-value');
        Assert::same(self::$lastData->getContext()['label'], 'FooName');
    }

    /**
     * Check the stacktrace contains three items and it begins with the right function name.
     *
     * The tick counter lives in a static variable of {@see tr()} and other tests call it too,
     * so the numbers are checked relative to the first call.
     */
    public function testTrAsStackTrace(): void
    {
        tr();

        // 3 Stack Trace items
        Assert::count(self::$lastData->getValue(), 3);
        $type = self::$lastData->getType();
        Assert::string($type)->matchesRegex('/^Trace #\d+  (?:-\.---|\+' . self::FLOAT . 'ms)  ' . self::FLOAT . 'M$/');
        $tick = (int) \substr($type, \strlen('Trace #'));
        $firstLineKey = \array_key_first(self::$lastData->getValue());
        Assert::string($firstLineKey)->contains('tr()');

        tr();

        Assert::count(self::$lastData->getValue(), 3);
        Assert::string(self::$lastData->getType())->matchesRegex('/^Trace #' . ($tick + 1) . '  \+' . self::FLOAT . 'ms  ' . self::FLOAT . 'M$/');
        $firstLineKey = \array_key_first(self::$lastData->getValue());
        Assert::string($firstLineKey)->contains('tr()');
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

        Assert::null($ref->get());
    }

    public function testReturn(): void
    {
        Assert::same(tr(42), 42);
        Assert::same(tr(named: 42), 42);
        Assert::same(tr(42, 43), 42);
        Assert::same(tr(...['0' => 'foo', 42 => 90]), 'foo');
        Assert::null(tr(null));
    }

    public function testReturnSendsDumpOnce(): void
    {
        $dumper = \Mockery::mock(DataDumperInterface::class);
        $dumper->shouldReceive('dump')->once()->andReturnNull();
        Dumper::setDumper($dumper);

        Assert::same(tr(42), 42);
    }
}
