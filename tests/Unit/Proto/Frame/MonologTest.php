<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Proto\Frame;

use Buggregator\Trap\Proto\Frame\Monolog;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Covers(Monolog::class)]
#[Test]
final class MonologTest
{
    public function testRoundTrip(): void
    {
        $message = ['message' => 'hello', 'context' => ['nested' => ['a' => 1]], 'level' => 200];
        $time = new \DateTimeImmutable();

        $frame = Monolog::fromString((string) new Monolog($message), $time);

        Assert::same($frame->message, $message);
        Assert::same($frame->time, $time);
    }

    public function testInvalidJsonThrowsJsonException(): never
    {
        Expect::exception(\JsonException::class);

        Monolog::fromString('not json', new \DateTimeImmutable());
    }

    public function testScalarJsonThrowsJsonException(): never
    {
        Expect::exception(\JsonException::class);

        Monolog::fromString('"just a string"', new \DateTimeImmutable());
    }
}
