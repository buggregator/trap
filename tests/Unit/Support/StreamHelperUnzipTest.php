<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Support;

use Buggregator\Trap\Support\StreamHelper;
use Buggregator\Trap\Tests\Unit\FiberTrait;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Covers(StreamHelper::class)]
final class StreamHelperUnzipTest
{
    use FiberTrait;

    #[Test]
    #[DataSet(['hello'], 'short')]
    #[DataSet([''], 'empty')]
    public function testInflatesBody(string $body): void
    {
        Assert::same($this->unzip(\gzencode($body)), $body);
    }

    #[Test]
    public function testInflatesBodyLargerThanOneChunk(): void
    {
        // Random bytes don't compress, so the gzip data spans several read chunks
        $body = \random_bytes(300 * 1024);

        Assert::same($this->unzip(\gzencode($body)), $body);
    }

    #[Test]
    public function testSuspendsBetweenChunks(): void
    {
        $request = (new ServerRequest('POST', '/'))->withBody(Stream::create(\gzencode(\random_bytes(300 * 1024))));
        $fiber = new \Fiber(static fn() => StreamHelper::unzipBody($request));

        $fiber->start();

        Assert::true($fiber->isSuspended());
    }

    #[Test]
    public function testCorruptedBodyBecomesEmpty(): void
    {
        Assert::same($this->unzip("\x1f\x8b\x08\x00garbage"), '');
    }

    #[Test]
    public function testTruncatedBodyKeepsInflatedPart(): void
    {
        $body = \str_repeat('abc', 10_000);
        $gzip = \gzencode($body);

        $inflated = $this->unzip(\substr($gzip, 0, \intdiv(\strlen($gzip), 2)));

        Assert::true(\str_starts_with($body, $inflated));
    }

    #[Test]
    public function testIgnoresDataAfterGzipEnd(): void
    {
        Assert::same($this->unzip(\gzencode('hello') . 'trailing'), 'hello');
    }

    private function unzip(string $gzip): string
    {
        $request = (new ServerRequest('POST', '/'))->withBody(Stream::create($gzip));

        return (string) $this->runInFiber(static fn() => StreamHelper::unzipBody($request))->getBody();
    }
}
