<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Support\Spx;

use Buggregator\Trap\Support\Spx\TraceReader;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Covers(TraceReader::class)]
#[Test]
final class TraceReaderTest
{
    private const STUB = __DIR__ . '/../../../Stub/spx/handwritten.txt';

    #[DataSet([1_000_000], 'whole')]
    #[DataSet([1], 'a byte at a time')]
    public function testReadsEventsAndNamesTheFunctionsAtTheEnd(int $chunkSize): void
    {
        $content = (string) \file_get_contents(self::STUB);
        $events = [];

        $names = TraceReader::read(
            \str_split(self::gz($content), $chunkSize),
            5,
            4,
            static function (int $function, bool $start, array $values) use (&$events): void {
                $events[] = [$function, $start, $values];
            },
        );

        Assert::same($names, ['/app/index.php', 'App\Kernel::handle', 'strlen', 'App\fib']);
        Assert::count($events, 12);
        Assert::same($events[0], [0, true, [0.0, 0.0, 0.0, 0.0, 0.0]]);
        Assert::same($events[11], [0, false, [12000.0, 10000.0, 50.0, 2.0, 8192.0]]);
    }

    public function testReadsTheNumbersAsSpxWritesThem(): void
    {
        $values = [];

        TraceReader::read(
            [self::gz("[events]\n0 1 0 .5 -.25  12.0001\n0 0 3 4 5 6 7\n[functions]\nmain\n")],
            5,
            1,
            static function (int $function, bool $start, array $read) use (&$values): void {
                $start and $values = $read;
            },
        );

        // A value that rounds to 0 at 4 decimals is written as nothing
        Assert::same($values, [0.0, 0.5, -0.25, 0.0, 12.0001]);
    }

    public function testReadsTraceWithoutMetrics(): void
    {
        $names = TraceReader::read([self::gz("[events]\n0 1\n0 0\n[functions]\nmain")], 0, 1, static function (): void {});

        Assert::same($names, ['main']);
    }

    public function testNamesEmptyFunctionLine(): void
    {
        $names = TraceReader::read([self::gz("[events]\n[functions]\n\nfoo\n")], 0, 2, static function (): void {});

        Assert::same($names, ['(unknown)', 'foo']);
    }

    public function testReadsFromStream(): void
    {
        $handle = \fopen('php://memory', 'w+b');
        \fwrite($handle, (string) \gzencode((string) \file_get_contents(self::STUB)));
        \rewind($handle);

        $names = TraceReader::read(TraceReader::chunks($handle, 16), 5, 4, static function (): void {});

        Assert::count($names, 4);
    }

    #[DataSet(['', 'The trace is empty.'], 'empty')]
    #[DataSet(["version: 1\n", 'Not an SPX trace: no `[events]` line.'], 'not a trace')]
    #[DataSet(["[events]\n0 1 5\n", 'Line 2 is not an event of 2 metric values.'], 'too few values')]
    #[DataSet(["[events]\n0 1 5 6 7\n", 'Line 2 is not an event of 2 metric values.'], 'too many values')]
    #[DataSet(["[events]\n0 1 5 x\n", 'Line 2 is not an event of 2 metric values.'], 'not a number')]
    #[DataSet(["[events]\n0 2 5 6\n", 'Line 2 is not an event of 2 metric values.'], 'neither start nor end')]
    #[DataSet(["[events]\n9 1 5 6\n", 'Line 2 refers to function 9, but the trace names 2.'], 'unknown function')]
    #[DataSet(["[events]\n0 0 5 6\n", 'Line 2 ends a call that is not the innermost open one.'], 'end of no call')]
    #[DataSet(["[events]\n0 1 5 6\n1 0 5 6\n", 'Line 3 ends a call that is not the innermost open one.'], 'end of another call')]
    #[DataSet(["[events]\n0 1 5 6\n[functions]\na\nb\n", 'The trace ends with calls still open.'], 'open call')]
    #[DataSet(["[events]\n0 1 5 6\n", 'The trace is truncated: no `[functions]` section.'], 'no functions')]
    #[DataSet(["[events]\n[functions]\na\n", 'The trace names 1 functions, the metadata tells 2.'], 'too few names')]
    #[DataSet(["[events]\n[functions]\na\nb\nc\n", 'The trace names more than the 2 functions of the metadata.'], 'too many names')]
    public function testRejectsMalformedTrace(string $trace, string $message): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage($message);

        TraceReader::read([self::gz($trace)], 2, 2, static function (): void {});
    }

    public function testRejectsTruncatedGzipStream(): never
    {
        $gzip = (string) \gzencode((string) \file_get_contents(self::STUB));

        Expect::exception(\InvalidArgumentException::class)
            ->withMessage('The trace is truncated: the gzip stream does not end.');

        TraceReader::read([\substr($gzip, 0, -12)], 5, 4, static function (): void {});
    }

    #[DataSet(["\x1f\x8b\x08\x00junk-junk-junk"], 'invalid gzip')]
    #[DataSet(["[events]\n[functions]\n"], 'plain')]
    public function testRejectsTraceThatIsNotGzip(string $trace): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('The trace is not gzip data, as SPX writes it.');

        TraceReader::read([$trace], 0, 0, static function (): void {});
    }

    public function testRejectsTraceOverSizeLimit(): never
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessage('The trace is larger than 64 bytes decompressed.');

        TraceReader::read([(string) \gzencode((string) \file_get_contents(self::STUB))], 5, 4, static function (): void {}, 64);
    }

    public function testRejectsNamesOverSizeLimit(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('The function names take more than 10 bytes.');

        TraceReader::read([self::gz("[events]\n[functions]\nApp\\Kernel::handle\nfoo\n")], 0, 2, static function (): void {}, maxNamesSize: 10);
    }

    public function testRejectsCallsNestedTooDeep(): never
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessage('Line ' . (TraceReader::MAX_DEPTH + 2) . ' opens a call deeper than ' . TraceReader::MAX_DEPTH . ' calls.');

        TraceReader::read([self::gz("[events]\n" . \str_repeat("0 1\n", TraceReader::MAX_DEPTH + 1))], 0, 1, static function (): void {});
    }

    public function testRejectsTooLongLine(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('Line 2 is longer than 65536 bytes.');

        // Incompressible: the line comes over several inflated chunks
        TraceReader::read([self::gz("[events]\n" . \bin2hex(\random_bytes(35_000)))], 2, 2, static function (): void {});
    }

    private static function gz(string $trace): string
    {
        return (string) \gzencode($trace, 1);
    }
}
