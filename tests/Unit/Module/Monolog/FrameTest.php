<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Monolog;

use Buggregator\Trap\Module\Monolog\Frame;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Covers(Frame::class)]
#[Test]
final class FrameTest
{
    public function testRoundTrip(): void
    {
        $message = ['message' => 'hello', 'context' => ['nested' => ['a' => 1]], 'level' => 200];
        $time = new \DateTimeImmutable();

        $frame = Frame::fromString((string) new Frame($message), $time);

        Assert::same($frame->message, $message);
        Assert::same($frame->time, $time);
    }

    public function testInvalidJsonThrowsJsonException(): never
    {
        Expect::exception(\JsonException::class);

        Frame::fromString('not json', new \DateTimeImmutable());
    }

    public function testScalarJsonThrowsJsonException(): never
    {
        Expect::exception(\JsonException::class);

        Frame::fromString('"just a string"', new \DateTimeImmutable());
    }
}
