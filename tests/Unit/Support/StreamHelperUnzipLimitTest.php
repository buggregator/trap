<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Support;

use Buggregator\Trap\Support\Stream\SizeLimitExceeded;
use Buggregator\Trap\Support\StreamHelper;
use Buggregator\Trap\Tests\Unit\FiberTrait;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Covers(StreamHelper::class)]
final class StreamHelperUnzipLimitTest
{
    use FiberTrait;

    #[Test]
    public function testBodyOfExactlyTheLimitIsInflated(): void
    {
        Assert::same($this->unzip(\str_repeat('a', 1000), 1000), \str_repeat('a', 1000));
    }

    #[Test]
    public function testBodyPastTheLimitThrows(): void
    {
        Expect::exception(SizeLimitExceeded::class)->withMessageContaining('more than 1000 bytes');

        $this->unzip(\str_repeat('a', 1001), 1000);
    }

    #[Test]
    public function testGzipBombPastTheLimitThrows(): void
    {
        Expect::exception(SizeLimitExceeded::class);

        // 32 MB of zeros compress to ~32 KB
        $this->unzip(\str_repeat("\0", 32 * 1024 * 1024), 1024 * 1024);
    }

    #[Test]
    public function testLimitCountsEveryInflatedChunk(): void
    {
        Expect::exception(SizeLimitExceeded::class)->withMessageContaining('more than 40000 bytes');

        // Random bytes do not compress: each read chunk inflates to less than the limit
        $this->unzip(\random_bytes(64 * 1024), 40_000);
    }

    private function unzip(string $body, int $maxSize): string
    {
        $request = (new ServerRequest('POST', '/'))->withBody(Stream::create(\gzencode($body)));

        return (string) $this->runInFiber(static fn() => StreamHelper::unzipBody($request, $maxSize))->getBody();
    }
}
