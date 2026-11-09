<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Monolog\Traffic;

use Buggregator\Trap\Module\Monolog\Traffic\Dispatcher;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(Dispatcher::class)]
final class DispatcherTest
{
    public static function detectProvider(): iterable
    {
        yield 'empty' => ['', null];
        yield 'opening brace' => ['{', null];
        yield 'prefix of the key' => ['{"mess', null];
        yield 'key without colon' => ['{"message"', null];
        yield 'whole key' => ['{"message":', true];
        yield 'record' => ['{"message":"hello","context":{}}' . "\n", true];
        yield 'other json' => ['{"level":200}', false];
        yield 'other short data' => ['GE', false];
    }

    #[DataProvider('detectProvider')]
    public function testDetect(string $data, ?bool $expected): void
    {
        $dispatcher = new Dispatcher();

        Assert::same($dispatcher->detect($data, new \DateTimeImmutable()), $expected);
    }
}
