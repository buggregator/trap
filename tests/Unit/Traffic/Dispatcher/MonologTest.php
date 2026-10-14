<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Traffic\Dispatcher;

use Buggregator\Trap\Traffic\Dispatcher\Monolog;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(Monolog::class)]
final class MonologTest
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
        $dispatcher = new Monolog();

        Assert::same($dispatcher->detect($data, new \DateTimeImmutable()), $expected);
    }
}
