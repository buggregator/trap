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

    public function testStackTrace(): void
    {
        $line = __FILE__ . ':' . __LINE__ and trap();

        $this->assertArrayHasKey('trace', static::$lastData->getValue());

        $neededLine = \array_key_first(static::$lastData->getValue()['trace']->getValue());

        $this->assertStringContainsString($line, $neededLine);
    }
}
