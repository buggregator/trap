<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Traffic\Dispatcher;

use Buggregator\Trap\Test\Mock\StreamClientMock;
use Buggregator\Trap\Tests\Unit\FiberTrait;
use Buggregator\Trap\Traffic\Dispatcher\VarDumper;
use Testo\Assert;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * @covers \Buggregator\Trap\Traffic\Dispatcher\Http
 */
final class VarDumperTest
{
    use FiberTrait;

    public static function detectProvider(): iterable
    {
        yield ["ABC\n", true];
        yield ["ABC\r\n", false];
        yield ["A B C\n", false];
    }

    #[DataProvider('detectProvider')]
    #[Test]
    public function testDetect(string $data, ?bool $expected): void
    {
        $dispatcher = new VarDumper();
        Assert::same($dispatcher->detect($data, new \DateTimeImmutable()), $expected);
    }

    #[Test]
    public function testDispatchFramesTime(): void
    {
        $dispatcher = new VarDumper();
        $stream = StreamClientMock::createFromGenerator((static function (): \Generator {
            yield "ABC\n";
            yield "DEF\n";
            yield "GHI\n";
        })());

        $resultGenerator = $dispatcher->dispatch($stream);

        $frames = $this->runInFiber(static fn() => \iterator_to_array($resultGenerator));

        Assert::count($frames, 3);
        Assert::notSame($frames[1]->time, $frames[0]->time);
        Assert::notSame($frames[2]->time, $frames[1]->time);
    }
}
