<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Traffic\Dispatcher;

use Buggregator\Trap\Test\Mock\StreamClientMock;
use Buggregator\Trap\Tests\Unit\FiberTrait;
use Buggregator\Trap\Traffic\Dispatcher\VarDumper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Buggregator\Trap\Traffic\Dispatcher\Http
 */
class VarDumperTest extends TestCase
{
    use FiberTrait;

    public static function detectProvider(): iterable
    {
        yield ["ABC\n", true];
        yield ["ABC\r\n", false];
        yield ["A B C\n", false];
    }

    #[DataProvider('detectProvider')]
    public function testDetect(string $data, ?bool $expected): void
    {
        $dispatcher = new VarDumper();
        $this->assertSame($expected, $dispatcher->detect($data, new \DateTimeImmutable()));
    }

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

        self::assertCount(3, $frames);
        self::assertNotSame($frames[0]->time, $frames[1]->time);
        self::assertNotSame($frames[1]->time, $frames[2]->time);
    }
}
