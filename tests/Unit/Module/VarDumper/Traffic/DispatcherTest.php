<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\VarDumper\Traffic;

use Buggregator\Trap\Module\VarDumper\Traffic\Dispatcher;
use Buggregator\Trap\Test\Mock\StreamClientMock;
use Buggregator\Trap\Tests\Unit\FiberTrait;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Covers(Dispatcher::class)]
final class DispatcherTest
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
        $dispatcher = new Dispatcher();
        Assert::same($dispatcher->detect($data, new \DateTimeImmutable()), $expected);
    }

    #[Test]
    public function testDispatchFramesTime(): void
    {
        $dispatcher = new Dispatcher();
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
