<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\XDebugTrace\Trace;

use Buggregator\Trap\Module\XDebugTrace\Trace\CallTree;
use Buggregator\Trap\Module\XDebugTrace\Trace\SlowestCalls;
use Buggregator\Trap\Module\XDebugTrace\Trace\Summary;
use Buggregator\Trap\Module\XDebugTrace\Trace\TraceError;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

#[Covers(Summary::class)]
#[Covers(SlowestCalls::class)]
#[Test]
final class SummaryTest
{
    private ?string $file = null;

    #[AfterTest]
    public function removeFile(): void
    {
        $this->file === null or @\unlink($this->file);
    }

    public function testSummarizesWholeTrace(): void
    {
        $summary = Summary::summarize(TraceReaderTest::MAIN);

        Assert::same($summary['calls'], 39);
        Assert::same($summary['user_calls'], 32);
        Assert::same($summary['max_depth'], 7);
        Assert::same($summary['assignments'], 24);
        Assert::true($summary['returns']);
        Assert::false($summary['truncated']);
        Assert::same($summary['main'], '/app/main.php');
        Assert::same($summary['duration'], 0.00143);
        Assert::same($summary['peak_memory'], 542304);
        Assert::same($summary['xdebug_version'], '3.4.5');
        Assert::same($summary['slowest'][0]['func'], '{main}');
        Assert::same($summary['slowest'][0]['offset'], 71);
        Assert::count($summary['slowest'], Summary::TOP_N);
        Assert::same(\array_column($summary['top'], 'func')[0], '{main}');
    }

    public function testOrdersSlowestCallsByInclusiveTime(): void
    {
        $times = \array_column(Summary::summarize(TraceReaderTest::MAIN)['slowest'], 'incl');

        $sorted = $times;
        \rsort($sorted);

        Assert::same($times, $sorted);
    }

    public function testCountsFunctionsOfRecursion(): void
    {
        $top = \array_column(Summary::summarize(TraceReaderTest::MAIN)['top'], null, 'func');

        Assert::same($top['App\fib']['count'], 25);
        Assert::true($top['App\fib']['user']);
        Assert::false($top['strtoupper']['user']);
    }

    public function testTraceStoppedInsideScriptIsNotCut(): void
    {
        $summary = Summary::summarize(TraceReaderTest::MID);

        Assert::same($summary['calls'], 12);
        Assert::false($summary['truncated']);
        Assert::same($summary['main'], '');
    }

    public function testTraceWithoutEndIsCut(): void
    {
        $plain = (string) \gzdecode((string) \file_get_contents(TraceReaderTest::MAIN));
        $this->file = (string) \tempnam(\sys_get_temp_dir(), 'trap-xt-test-');
        \file_put_contents($this->file, \substr($plain, 0, 2000));

        $summary = Summary::summarize($this->file);

        Assert::true($summary['truncated']);
        Assert::same($summary['end'], '');
    }

    public function testTraceOverLimitIsCut(): void
    {
        $summary = Summary::summarize(TraceReaderTest::MAIN, 1000);

        Assert::true($summary['truncated']);
        Assert::int($summary['calls'])->lessThan(39);
    }

    public function testRejectsOtherContent(): never
    {
        $this->file = (string) \tempnam(\sys_get_temp_dir(), 'trap-xt-test-');
        \file_put_contents($this->file, "{\"profile\": []}\n");

        Expect::exception(TraceError::class);

        Summary::summarize($this->file);
    }

    public function testReadsMalformedTraceInLinearTime(): void
    {
        // Deep calls, then exits of calls never entered: a scan of the open calls per exit is quadratic
        $lines = ["File format: 4"];
        for ($i = 1; $i <= 20_000; ++$i) {
            $lines[] = "$i\t$i\t0\t0.1\t100\tf\t1\t\t/a.php\t1\t0";
        }
        for ($i = 0; $i < 20_000; ++$i) {
            $lines[] = "1\t9$i\t1\t0.2\t100";
        }
        $this->file = (string) \tempnam(\sys_get_temp_dir(), 'trap-xt-test-');
        \file_put_contents($this->file, \implode("\n", $lines) . "\n");
        $start = \microtime(true);

        $summary = Summary::summarize($this->file);
        (new CallTree($this->file))->search('f', 0, 10);

        Assert::same($summary['calls'], 20_000);
        Assert::true($summary['truncated']);
        Assert::float(\microtime(true) - $start)->lessThan(5.0);
    }

    public function testKeepsSlowestCallsOnTie(): void
    {
        $heap = new SlowestCalls(2);
        foreach ([[1, 0.5], [2, 0.5], [3, 0.1], [4, 0.9]] as [$nr, $incl]) {
            $heap->push(['nr' => $nr, 'func' => 'f', 'file' => '', 'line' => 0, 'level' => 1, 'start' => 0.0, 'incl' => $incl, 'self' => 0.0, 'memory' => 0, 'offset' => 0]);
        }

        Assert::same(\array_column($heap->sorted(), 'nr'), [4, 1]);
    }
}
