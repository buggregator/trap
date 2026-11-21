<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\XDebugTrace\Trace;

use Buggregator\Trap\Module\XDebugTrace\Trace\Record;
use Buggregator\Trap\Module\XDebugTrace\Trace\TraceError;
use Buggregator\Trap\Module\XDebugTrace\Trace\TraceReader;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

#[Covers(TraceReader::class)]
#[Covers(Record::class)]
#[Test]
final class TraceReaderTest
{
    public const MAIN = __DIR__ . '/../../../../Stub/xdebug-trace/main.xt.gz';
    public const MID = __DIR__ . '/../../../../Stub/xdebug-trace/mid.xt.gz';

    /** @var list<string> */
    private array $files = [];

    #[AfterTest]
    public function removeFiles(): void
    {
        foreach ($this->files as $file) {
            @\unlink($file);
        }
    }

    public function testReadsHeaderAndRecordsOfGzippedTrace(): void
    {
        $reader = TraceReader::open(self::MAIN);

        $kinds = [];
        $first = null;
        while (($record = $reader->next()) !== null) {
            $first ??= $record;
            $kinds[$record->kind] = ($kinds[$record->kind] ?? 0) + 1;
        }

        Assert::same($reader->version, '3.4.5');
        Assert::same($reader->fileFormat, 4);
        Assert::same($reader->start, '2026-11-08 17:24:21.055261');
        Assert::same($reader->end, '2026-11-08 17:24:21.055572');
        Assert::same($reader->endTime, 0.00143);
        Assert::same($reader->endMemory, 530336);
        Assert::same($kinds, [Record::ENTRY => 39, Record::EXIT => 39, Record::ASSIGN => 24, Record::RETURN => 36]);
        Assert::same($first?->func, '{main}');
        Assert::same($first->file, '/app/main.php');
        Assert::true($first->user);
    }

    public function testOffsetsPointAtEntryLinesOfUncompressedTrace(): void
    {
        $plain = $this->plain(self::MAIN);
        $reader = TraceReader::open(self::MAIN);

        while (($record = $reader->next()) !== null) {
            $record->kind === Record::ENTRY and Assert::string(\substr($plain, $reader->offset(), 30))
                ->startsWith("{$record->level}\t{$record->nr}\t0\t");
        }
    }

    public function testReadsPlainTraceAsGzipped(): void
    {
        $path = $this->file($this->plain(self::MID));

        $reader = TraceReader::open($path);
        $record = $reader->next();

        Assert::same($record?->func, 'App\fib');
        Assert::same($record->level, 5);
        Assert::same($record->args, ['4']);
    }

    public function testSkipsToOffsetInBothForms(): void
    {
        $plain = $this->file($this->plain(self::MAIN));

        foreach ([$plain, self::MAIN] as $path) {
            $reader = TraceReader::open($path);
            $reader->skipTo(1998);

            Assert::same($reader->next()?->func, 'App\fib');
            Assert::same($reader->offset(), 1998);
        }
    }

    public function testRejectsOffsetBehindReadData(): never
    {
        $reader = TraceReader::open(self::MAIN);
        $reader->next();
        $reader->next();

        Expect::exception(TraceError::class)->withMessage('The offset is not the start of a call.');

        $reader->skipTo(0);
    }

    public function testRejectsOffsetPastEnd(): never
    {
        $reader = TraceReader::open($this->file($this->plain(self::MID)));

        Expect::exception(TraceError::class);

        $reader->skipTo(1 << 20);
    }

    public function testRejectsOtherFormats(): never
    {
        $reader = TraceReader::open($this->file("Version: 3.4.5\nFile format: 4\nTRACE START [x]\n    0.0001  {main}() /app/a.php:0\n"));

        Expect::exception(TraceError::class)->withMessageContaining('xdebug.trace_format=1');

        $reader->next();
    }

    public function testRejectsEmptyFile(): never
    {
        $reader = TraceReader::open($this->file(''));

        Expect::exception(TraceError::class);

        $reader->next();
    }

    public function testStopsAtSizeLimit(): never
    {
        $reader = TraceReader::open(self::MAIN, 200);

        Expect::exception(TraceError::class)->withMessage('The trace is larger than the limit of 200 bytes.');

        while ($reader->next() !== null);
    }

    public function testCutsLongLine(): void
    {
        $long = \str_repeat('x', TraceReader::MAX_LINE + 10);
        $path = $this->file("File format: 4\n1\t0\t0\t0.1\t100\t{main}\t1\t\t/a.php\t0\t1\t'$long'\n1\t0\t1\t0.2\t100\n");
        $reader = TraceReader::open($path);

        $entry = $reader->next();
        $exit = $reader->next();

        Assert::true($reader->cut);
        Assert::same($entry?->func, '{main}');
        Assert::same($exit?->kind, Record::EXIT);
        Assert::same($exit->time, 0.2);
    }

    public function testStopsAtSizeLimitInsideLongLine(): never
    {
        $long = \str_repeat('x', 3 * TraceReader::MAX_LINE);
        $reader = TraceReader::open($this->file("File format: 4\n1\t0\t0\t0.1\t100\t{main}\t1\t\t/a.php\t0\t1\t'$long'\n"), 2 * TraceReader::MAX_LINE);

        Expect::exception(TraceError::class);

        $reader->next();
    }

    public function testSkipsStrayLineInsideTrace(): void
    {
        $path = $this->file("File format: 4\n1\t0\t0\t0.1\t100\t{main}\t1\t\t/a.php\t0\t0\ngarbage\n1\t0\t1\t0.2\t100\n");
        $reader = TraceReader::open($path);

        $reader->next();

        Assert::same($reader->next()?->kind, Record::EXIT);
        Assert::null($reader->next());
    }

    public function testParsesReturnAndAssignment(): void
    {
        $return = Record::parse("8\t9\tR\t\t\t'a\tb'");
        $assign = Record::parse("2\t\tA\t\t\t\t\t\t/app/lib.php\t9\t\$i = 1");

        Assert::same($return?->value, "'a\tb'");
        Assert::same($return->nr, 9);
        Assert::same($assign?->value, '$i = 1');
        Assert::same($assign->line, 9);
        Assert::null(Record::parse("x\t1\t0"));
        Assert::null(Record::parse("1\t1\t7"));
    }

    private function plain(string $gz): string
    {
        return (string) \gzdecode((string) \file_get_contents($gz));
    }

    private function file(string $content): string
    {
        $path = (string) \tempnam(\sys_get_temp_dir(), 'trap-xt-test-');
        \file_put_contents($path, $content);

        return $this->files[] = $path;
    }
}
