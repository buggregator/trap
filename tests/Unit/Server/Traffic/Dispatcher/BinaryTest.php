<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Traffic\Dispatcher;

use Buggregator\Trap\Server\Proto\Frame;
use Buggregator\Trap\Server\Traffic\Dispatcher\Binary;
use Buggregator\Trap\Support\StreamHelper;
use Buggregator\Trap\Test\Mock\StreamClientMock;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(Binary::class)]
#[Covers(StreamHelper::class)]
#[Test]
final class BinaryTest
{
    public function testFrameHasAllBytesOfConnection(): void
    {
        $frames = self::dispatch(["\x00\x01", 'binary']);

        Assert::count($frames, 1);
        Assert::instanceOf($frames[0], Frame\Binary::class);
        Assert::same((string) $frames[0]->stream, "\x00\x01binary");
    }

    /**
     * An event keeps the stream of its connection: a large one must not stay in memory.
     */
    public function testLargeConnectionGoesToFile(): void
    {
        $chunk = \str_repeat("\x00\x01", 32 * 1024);
        $before = \memory_get_usage();

        $frames = self::dispatch(\array_fill(0, 32, $chunk));

        Assert::same($frames[0]->getSize(), 32 * \strlen($chunk));
        Assert::true(\memory_get_usage() - $before < 512 * 1024);
    }

    /**
     * @param list<string> $chunks
     * @return list<Frame\Binary>
     */
    private static function dispatch(array $chunks): array
    {
        $client = StreamClientMock::createFromGenerator((static function () use ($chunks): \Generator {
            foreach ($chunks as $chunk) {
                yield $chunk;
            }
        })());

        $fiber = new \Fiber(static fn(): array => [...(new Binary())->dispatch($client)]);
        $fiber->start();
        while (!$fiber->isTerminated()) {
            $fiber->resume();
        }

        /** @var list<Frame\Binary> */
        return $fiber->getReturn();
    }
}
