<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\FilesObserver;

use Buggregator\Trap\Module\Profiler\FilesObserver\GzipFollower;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Covers(GzipFollower::class)]
#[Test]
final class GzipFollowerTest
{
    private string $path;

    #[BeforeTest]
    public function createFile(): void
    {
        $this->path = \sys_get_temp_dir() . '/' . \uniqid('trap-gzip-', true) . '.gz';
    }

    #[AfterTest]
    public function removeFile(): void
    {
        \is_file($this->path) and \unlink($this->path);
    }

    public function testInflatesOnlyTheBytesAddedSinceTheLastCheck(): void
    {
        $data = (string) \gzencode(\random_bytes(200_000), 1);
        $half = \intdiv(\strlen($data), 2);
        \file_put_contents($this->path, \substr($data, 0, $half));
        $follower = new GzipFollower(16);

        Assert::same($this->follow($follower), null);

        // The bytes read before are not read again: garbage there, before the bytes kept to tell a file written anew,
        // breaks only a check that would inflate from the start
        $handle = \fopen($this->path, 'r+b');
        \fwrite($handle, \str_repeat("\xff", $half - 64));
        \fclose($handle);
        \file_put_contents($this->path, \substr($data, $half), \FILE_APPEND);

        $tail = $this->follow($follower);
        Assert::true(\is_string($tail) && \strlen($tail) === 16);
        Assert::same($follower->count(), 0);
    }

    public function testReadsLargeFileOverSeveralCalls(): void
    {
        $data = (string) \gzencode(\random_bytes(100_000), 1);
        \file_put_contents($this->path, $data);
        $follower = new GzipFollower(16, maxRead: 20_000);

        $calls = 1;
        while (($tail = $this->follow($follower)) === null) {
            ++$calls;
        }

        Assert::same($calls, (int) \ceil(\strlen($data) / 20_000));
        Assert::true(\is_string($tail) && \strlen($tail) === 16);
    }

    public function testForgetsFileNoLongerFollowed(): void
    {
        $data = (string) \gzencode(\random_bytes(100_000), 1);
        \file_put_contents($this->path, \substr($data, 0, 1000));
        $follower = new GzipFollower();

        $this->follow($follower);
        Assert::same($follower->count(), 1);

        $follower->forget($this->path);
        Assert::same($follower->count(), 0);
    }

    private function follow(GzipFollower $follower): string|false|null
    {
        $handle = \fopen($this->path, 'rb');
        try {
            return $follower->follow($this->path, $handle);
        } finally {
            \fclose($handle);
        }
    }
}
