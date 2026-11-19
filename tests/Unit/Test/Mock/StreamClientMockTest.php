<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Test\Mock;

use Buggregator\Trap\Server\Traffic\FetchLineTrait;
use Buggregator\Trap\Server\Traffic\StreamClient;
use Buggregator\Trap\Test\Mock\StreamClientMock;
use Buggregator\Trap\Test\Mock\StreamClientMock\ClientScript;
use Buggregator\Trap\Tests\Unit\FiberTrait;
use Buggregator\Trap\Tests\Unit\Server\Socket\SocketStreamTest;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

/**
 * After its packets, the client sends nothing more and keeps the connection open unless it closes it: a reader
 * that waits for data fails the test instead of hanging it.
 */
#[Covers(StreamClientMock::class)]
#[Covers(FetchLineTrait::class)]
final class StreamClientMockTest
{
    use FiberTrait;

    /**
     * @param non-empty-list<string> $chunks
     * @param non-empty-list<string> $lines
     */
    #[DataProvider(SocketStreamTest::class . '::queuedChunks')]
    #[Test]
    public function fetchLineReadsQueuedChunks(array $chunks, array $lines, string $rest): void
    {
        $stream = self::queue(false, ...$chunks);

        foreach ($lines as $line) {
            Assert::same($this->fetchLine($stream), $line);
        }
        Assert::same($stream->getData(), $rest);
    }

    #[Test]
    public function fetchLineReturnsQueuedChunksOnDisconnect(): void
    {
        $stream = self::queue(true, 'par', 'tial');

        Assert::same($this->fetchLine($stream), 'partial');
        Assert::true($stream->isFinished());
    }

    /**
     * Each chunk is a separate read, and all of them are queued before the reader runs.
     */
    private static function queue(bool $close, string ...$chunks): StreamClient
    {
        $client = (new ClientScript())->send(...$chunks);
        $close and $client->close();
        $stream = StreamClientMock::createFromGenerator($client->generator());
        // The client sends its next packet on each reply
        for ($i = 1; $i < \count($chunks); ++$i) {
            $stream->sendData('ack');
        }

        Assert::same($stream->getData(), \implode('', $chunks));
        return $stream;
    }

    private function fetchLine(StreamClient $stream): string
    {
        $fiber = new \Fiber($stream->fetchLine(...));

        Assert::true($this->runFiberAtMost($fiber), 'The reader waits for data that is already queued.');
        return $fiber->getReturn();
    }
}
