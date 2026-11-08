<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Client\Caster;

use Testo\Assert;
use Testo\Test;

#[Test]
final class TraceFileTest
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

        Assert::same((string) $traceFile, 'file.php:42');
    }

    public function testToStringWithoutFile(): void
    {
        $traceFile = new \Buggregator\Trap\Client\Caster\TraceFile([
            'function' => 'foo',
        ]);

        Assert::same((string) $traceFile, '<internal>');
    }

    public function testToStringWithoutLine(): void
    {
        $traceFile = new \Buggregator\Trap\Client\Caster\TraceFile([
            'function' => 'foo',
            'file' => '/path/to/file.php',
        ]);

        Assert::same((string) $traceFile, '<internal>');
    }
}
