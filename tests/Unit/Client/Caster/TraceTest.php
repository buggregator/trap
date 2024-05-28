<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Client\Caster;

use Buggregator\Trap\Client\Caster\Trace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TraceTest extends TestCase
{
    public static function provideToString(): iterable
    {
        yield [0, 0.0, 102400, 'Trace #0  -.---  0.10M'];
        yield [5, 0.000123, 123456, 'Trace #5  +0.123ms  0.12M'];
        yield [4, 0.00123, 123456, 'Trace #4  +1.23ms  0.12M'];
        yield [3, 0.123, 123456, 'Trace #3  +123.0ms  0.12M'];
        yield [2, 1.23, 123456, 'Trace #2  +1.23s  0.12M'];
        yield [1, 42.3, 123456, 'Trace #1  +42.3s  0.12M'];
        yield [42, 10050.0, 0, 'Trace #42  +30m 30s  0.00M'];
    }

    /**
     * @param int<0, max> $number
     * @param int<0, max> $memory
     * @param non-empty-string $result
     */
    #[DataProvider('provideToString')]
    public function testToString(int $number, float $delta, int $memory, string $result): void
    {
        $trace = new Trace(
            number: $number,
            delta: $delta,
            memory: $memory,
            stack: [
                [
                    'function' => 'foo',
                    'line' => 42,
                    'file' => '/path/to/file.php',
                    'class' => 'Foo',
                    'type' => '->',
                    'args' => ['bar'],
                ],
                [
                    'function' => 'bar',
                    'line' => 23,
                    'file' => '/path/to/file.php',
                    'class' => 'Bar',
                    'type' => '::',
                    'args' => ['baz'],
                ],
            ],
        );

        self::assertSame($result, (string) $trace);
    }
}
