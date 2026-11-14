<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Proto;

use Buggregator\Trap\Server\Proto\Buffer;
use Buggregator\Trap\Server\Proto\Frame\Binary;
use Nyholm\Psr7\Stream;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(Buffer::class)]
final class BufferTest
{
    public function isReadyOnlyPastTheSizeLimit(): void
    {
        $buffer = new Buffer(bufferSize: 10);

        $buffer->addFrame(self::frame(10));
        Assert::same($buffer->getSize(), 10);
        Assert::false($buffer->isOverflow());
        Assert::false($buffer->isReady());

        $buffer->addFrame(self::frame(1));

        Assert::same($buffer->getSize(), 11);
        Assert::true($buffer->isOverflow());
        Assert::true($buffer->isReady());
    }

    public function getAndCleanTakesAllFrames(): void
    {
        $buffer = new Buffer(bufferSize: 10);
        $first = self::frame(6);
        $second = self::frame(6);
        $buffer->addFrame($first);
        $buffer->addFrame($second);

        Assert::same($buffer->getAndClean(), [$first, $second]);
        Assert::same($buffer->getSize(), 0);
        Assert::false($buffer->isReady());
        Assert::same($buffer->getAndClean(), []);
    }

    public function timerStartsWithTheFirstFrame(): void
    {
        $buffer = new Buffer(bufferSize: 1000, timer: 0.1);
        \usleep(150_000);
        Assert::false($buffer->isReady());

        $buffer->addFrame(self::frame(1));
        \usleep(150_000);

        Assert::true($buffer->isReady());
    }

    public function timerStopsWhenCleaned(): void
    {
        $buffer = new Buffer(bufferSize: 1000, timer: 0.1);
        $buffer->addFrame(self::frame(1));
        \usleep(150_000);

        $buffer->getAndClean();

        Assert::false($buffer->isReady());
    }

    public function timerWaitsTheGivenTime(): void
    {
        $buffer = new Buffer(bufferSize: 1000, timer: 3600.0);
        $buffer->addFrame(self::frame(1));

        \usleep(150_000);

        Assert::false($buffer->isReady());
    }

    private static function frame(int $size): Binary
    {
        return new Binary(Stream::create(\str_repeat('x', $size)));
    }
}
