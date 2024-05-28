<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Client\Caster;

use PHPUnit\Framework\TestCase;

final class TraceFileTest extends TestCase
{
    public function testToString(): void
    {
        $traceFile = new \Buggregator\Trap\Client\Caster\TraceFile([
            'function' => 'foo',
            'line' => 42,
            'file' => '/path/to/file.php',
            'class' => 'Foo',
            'type' => '->',
            'args' => ['bar'],
        ]);

        self::assertSame('file.php:42', (string) $traceFile);
    }

    public function testToStringWithoutFile(): void
    {
        $traceFile = new \Buggregator\Trap\Client\Caster\TraceFile([
            'function' => 'foo',
        ]);

        self::assertSame('<internal>', (string) $traceFile);
    }

    public function testToStringWithoutLine(): void
    {
        $traceFile = new \Buggregator\Trap\Client\Caster\TraceFile([
            'function' => 'foo',
            'file' => '/path/to/file.php',
        ]);

        self::assertSame('<internal>', (string) $traceFile);
    }
}
