<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Stacks;

use Buggregator\Trap\Module\Profiler\Compaction\Nodes;
use Buggregator\Trap\Module\Profiler\Compaction\StackTree;
use Buggregator\Trap\Module\Profiler\Stacks\Functions;
use Buggregator\Trap\Module\Profiler\Stacks\ProfileBuilder;
use Buggregator\Trap\Module\Profiler\Struct\Branch;
use Buggregator\Trap\Module\Profiler\Struct\Edge;
use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Support\TimeSlice;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Test;

#[Covers(ProfileBuilder::class)]
#[Covers(Functions::class)]
#[Covers(Nodes::class)]
#[Covers(StackTree::class)]
#[Test]
final class ProfileBuilderTest
{
    public function testMergesPhpspySamplesFromLeafUnderMain(): void
    {
        $trace = <<<'TRACE'
            0 usleep <internal>:-1
            1 App\Foo::bar /app/src/Foo.php:12
            2 <main> /app/index.php:5

            0 App\Foo::bar /app/src/Foo.php:14
            1 <main> /app/index.php:7

            0 <main> /app/index.php:9


            TRACE;

        $profile = (new ProfileBuilder())->phpspy($trace);

        Assert::same(self::shape($profile), [
            'main():0:3' => ['App\Foo::bar:0:2' => ['usleep:0:1' => []]],
        ]);
        Assert::same($profile->metadata, ['profile_type' => 'sampled']);
    }

    public function testMeasuresWallTimeByPeriod(): void
    {
        $trace = "0 foo /app/a.php:1\n1 <main> /app/a.php:2\n\n0 <main> /app/a.php:3\n\n";

        $profile = (new ProfileBuilder())->phpspy($trace, period: 0.01);

        Assert::same(self::shape($profile), ['main():20000:2' => ['foo:10000:1' => []]]);
        Assert::same($profile->metadata, ['profile_type' => 'sampled', 'clock' => 'wall', 'period' => 0.01]);
    }

    public function testSkipsMetadataLines(): void
    {
        $trace = <<<'TRACE'
            # uri = /checkout
            # ts = 1700000000.5
            0 foo /app/a.php:1
            # varpeek i@/app/a.php:1 = 42
            1 <main> /app/a.php:2
            # mem 2097152 4194304
            # pid = 42


            TRACE;

        $profile = (new ProfileBuilder())->phpspy($trace);

        Assert::same(self::shape($profile), ['main():0:1' => ['foo:0:1' => []]]);
    }

    public function testJoinsChunksOfSample(): void
    {
        // phpspy splits a sample longer than its buffer into chunks; another process writes between them
        $trace = <<<'TRACE'
            0 leaf /app/a.php:1
            # trace_id = 3.0~

            0 other /app/a.php:5
            1 <main> /app/a.php:6

            1 middle /app/a.php:2
            # trace_id = 3.1~

            2 <main> /app/a.php:3
            # trace_id = 3.2!


            TRACE;

        $profile = (new ProfileBuilder())->phpspy($trace);

        Assert::same(self::shape($profile), [
            'main():0:2' => ['other:0:1' => [], 'middle:0:1' => ['leaf:0:1' => []]],
        ]);
    }

    public function testDropsSampleWithLostChunk(): void
    {
        $trace = "0 lost /app/a.php:1\n# trace_id = 1.1!\n\n0 kept /app/a.php:1\n1 <main> /app/a.php:2\n\n";

        $profile = (new ProfileBuilder())->phpspy($trace);

        Assert::same(self::shape($profile), ['main():0:1' => ['kept:0:1' => []]]);
    }

    public function testKeepsLeafSideOfTruncatedSample(): void
    {
        $trace = "0 leaf /app/a.php:1\n1 inner /app/a.php:2\n# trace_id = 0.0~\n\n# truncated = 1\n# trace_id = 0.1!\n\n";

        $profile = (new ProfileBuilder())->phpspy($trace);

        Assert::same(self::shape($profile), ['inner:0:1' => ['leaf:0:1' => []]]);
    }

    public function testReadsSingleLineMode(): void
    {
        $trace = "0 foo /app/a.php:1\t1 <main> /app/a.php:2\t\n0 <main> /app/a.php:3\t\n";

        $profile = (new ProfileBuilder())->phpspy($trace);

        Assert::same(self::shape($profile), ['main():0:2' => ['foo:0:1' => []]]);
    }

    public function testReadsReliOpcodeTemplate(): void
    {
        $trace = "0 <VM>::ZEND_DO_ICALL <VM>:-1\n1 App\\Foo::bar /app/Foo.php:12:ZEND_DO_ICALL\n2 <main> /app/index.php:5\n\n";

        $profile = (new ProfileBuilder())->phpspy($trace);

        Assert::same(self::shape($profile), [
            'main():0:1' => ['App\Foo::bar:0:1' => ['<VM>::ZEND_DO_ICALL:0:1' => []]],
        ]);
    }

    public function testTellsFunctionsOfSameNameApartByFile(): void
    {
        $trace = <<<'TRACE'
            0 {closure} /app/routes.php:3
            1 <main> /app/routes.php:9
            2 <main> /app/index.php:5

            0 {closure} /app/web.php:7
            1 <main> /app/index.php:5

            0 strlen <internal>:-1
            1 <main> /app/index.php:5


            TRACE;

        $profile = (new ProfileBuilder())->phpspy($trace);

        Assert::same(self::shape($profile), [
            'main():0:3' => [
                '<main> (/app/routes.php):0:1' => ['{closure} (/app/routes.php):0:1' => []],
                '{closure} (/app/web.php):0:1' => [],
                'strlen:0:1' => [],
            ],
        ]);
    }

    public function testReadsCrlf(): void
    {
        $profile = (new ProfileBuilder())->phpspy("0 foo /app/a.php:1\r\n1 <main> /app/a.php:2\r\n\r\n");

        Assert::same(self::shape($profile), ['main():0:1' => ['foo:0:1' => []]]);
    }

    #[DataSet(["0 foo /app/a.php:1\n1 <main> /app/a.php:2\n\n0 bar /app/a.php:1\n1 <main> /app/a"], 'record cut off')]
    #[DataSet(["0 foo /app/a.php:1\n1 <main> /app/a.php:2\n\n0 bar /app/a.php:1\n"], 'sample without its end')]
    #[DataSet(["0 foo /app/a.php:1\t1 <main> /app/a.php:2\t\n0 bar /app/a.php:1\t1 <ma"], 'single-line')]
    public function testDropsSampleCutOffAtEnd(string $trace): void
    {
        $profile = (new ProfileBuilder())->phpspy($trace);

        Assert::same(self::shape($profile), ['main():0:1' => ['foo:0:1' => []]]);
    }

    public function testTakesMetadataEndingWithTabInDefaultMode(): void
    {
        // phpspy cuts a peeked value to 255 bytes: tab-indented SQL may end with a tab
        $trace = "0 PDOStatement::execute <internal>:-1\n"
            . "# varpeek #pdo_sql@PDOStatement::execute = SELECT id\tFROM users\t\n"
            . "1 App\\Repo::find /app/Repo.php:8\n2 <main> /app/main.php:3\n\n";

        $profile = (new ProfileBuilder())->phpspy($trace);

        Assert::same(self::shape($profile), [
            'main():0:1' => ['App\Repo::find:0:1' => ['PDOStatement::execute:0:1' => []]],
        ]);
    }

    public function testWalksSingleLineOfManyRecordsInLittleMemory(): void
    {
        $trace = "0 foo /app/a.php:1\t1 <main> /app/a.php:2\t\n" . \str_repeat("\t", 8_000_000) . "\n";
        \memory_reset_peak_usage();
        $before = \memory_get_usage();

        try {
            (new ProfileBuilder())->phpspy($trace);
            $error = null;
        } catch (\InvalidArgumentException $e) {
            $error = $e->getMessage();
        }

        Assert::same($error, 'The profile has more than 4000000 lines or records.');
        // An array of the 8 million empty records would take over 128 MB
        Assert::true(\memory_get_peak_usage() - $before < 16 * 1024 * 1024);
    }

    public function testRejectsPeriodTooLongForSamples(): void
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessage('The wall time is too large: the `period` is too long.');

        (new ProfileBuilder())->phpspy("0 foo /app/a.php:1\n\n", period: 1e300);
    }

    #[DataSet(["hello\n"], 'not a frame')]
    #[DataSet(["0 foo\n"], 'no file')]
    #[DataSet(["x0 foo /app/a.php:1\n"], 'text before depth')]
    #[DataSet(["0 foo /app/a.php:1x\n"], 'text after line')]
    public function testRejectsWhatIsNotPhpspyFrame(string $trace): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Line 1 is not a phpspy frame');

        (new ProfileBuilder())->phpspy($trace);
    }

    #[DataSet([''], 'empty')]
    #[DataSet(["# uri = /\n\n"], 'only metadata')]
    public function testRejectsTraceWithoutSamples(string $trace): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('The trace has no samples.');

        (new ProfileBuilder())->phpspy($trace);
    }

    public function testMergesCollapsedStacksFromRoot(): void
    {
        $profile = (new ProfileBuilder())->collapsed("<main>;foo;bar 3\n<main>;foo 2\n\n<main>;baz 1\n");

        Assert::same(self::shape($profile), [
            'main():0:6' => ['foo:0:5' => ['bar:0:3' => []], 'baz:0:1' => []],
        ]);
    }

    public function testPutsCollapsedRootsUnderMain(): void
    {
        $profile = (new ProfileBuilder())->collapsed("a;b 2\nc 1\n;d 1\n", period: 0.001);

        Assert::same(self::shape($profile), [
            'main():4000:4' => ['a:2000:2' => ['b:2000:2' => []], 'c:1000:1' => [], '(unknown):1000:1' => ['d:1000:1' => []]],
        ]);
    }

    public function testSkipsCollapsedStacksOfZeroCount(): void
    {
        $profile = (new ProfileBuilder())->collapsed("main;foo 0\nmain;bar 1\n");

        Assert::same(self::shape($profile), ['main():0:1' => ['bar:0:1' => []]]);
    }

    #[DataSet(["main;foo\n"], 'no count')]
    #[DataSet(["main;foo 1.5\n"], 'fraction')]
    public function testRejectsWhatIsNotCollapsedStack(string $content): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Line 1 is not a collapsed stack');

        (new ProfileBuilder())->collapsed($content);
    }

    public function testRejectsCollapsedWithoutSamples(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('The profile has no samples.');

        (new ProfileBuilder())->collapsed("main 0\n");
    }

    public function testDecodesGzip(): void
    {
        $content = "0 foo /app/a.php:1\n";

        Assert::same((new ProfileBuilder())->decode(\gzencode($content)), $content);
        Assert::same((new ProfileBuilder())->decode($content), $content);
    }

    public function testRejectsOversizedContent(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('The profile decompresses to more than 16 bytes.');

        (new ProfileBuilder(maxInflatedSize: 16))->decode(\gzencode(\str_repeat('x', 100)));
    }

    public function testCompactsOverNodeLimit(): void
    {
        $content = '';
        for ($i = 0; $i < 50; ++$i) {
            $content .= "main;f$i 1\n";
        }

        $profile = (new ProfileBuilder(maxNodes: 10))->collapsed($content);

        Assert::same($profile->metadata['compacted']['limit'], 10);
    }

    public function testReadsMetadataAndChunksOfSingleLineMode(): void
    {
        // A record ends with the frame delimiter, a tab here: metadata and chunk trailers share the line
        $trace = "0 leaf /app/a.php:1\t# trace_id = 5.0~\t\n"
            . "0 foo /app/a.php:3\t1 <main> /app/a.php:4\t# mem 1 2\t# pid = 7\t\n"
            . "1 <main> /app/a.php:2\t# trace_id = 5.1!\t\n";

        $profile = (new ProfileBuilder())->phpspy($trace);

        Assert::same(self::shape($profile), ['main():0:2' => ['foo:0:1' => [], 'leaf:0:1' => []]]);
    }

    public function testReadsSingleLineStartingWithPeekedVariable(): void
    {
        // `-e` writes the peeked value before the frame it peeks into
        $trace = "# varpeek i@/app/a.php:1 = 1\t0 foo /app/a.php:1\t1 <main> /app/a.php:2\t\n"
            . "# varpeek i@/app/a.php:1 = 2\t0 foo /app/a.php:1\t1 <main> /app/a.php:2\t\n"
            . "0 bar /app/a.php:5\t1 <main> /app/a.php:6\t\n";

        $profile = (new ProfileBuilder())->phpspy($trace);

        Assert::same(self::shape($profile), ['main():0:3' => ['foo:0:2' => [], 'bar:0:1' => []]]);
    }

    public function testKeepsTruncatedSampleOfSingleLineMode(): void
    {
        $trace = "0 leaf /app/a.php:1\t# trace_id = 0.0~\t\n# truncated = 1\t# trace_id = 0.1!\t\n";

        $profile = (new ProfileBuilder())->phpspy($trace);

        Assert::same(self::shape($profile), ['leaf:0:1' => []]);
    }

    public function testDropsSampleWithHugeChunkIndex(): void
    {
        $trace = "0 lost /app/a.php:1\n# trace_id = 1.100000000!\n\n0 kept /app/a.php:1\n\n";

        $profile = (new ProfileBuilder())->phpspy($trace);

        Assert::same(self::shape($profile), ['kept:0:1' => []]);
    }

    public function testReadsClosureNameWithSpace(): void
    {
        $trace = "0 {closure:/my app/x.php:3} /my app/x.php:4\n1 <main> /my app/x.php:9\n\n";

        $profile = (new ProfileBuilder())->phpspy($trace);

        Assert::same(self::shape($profile), ['main():0:1' => ['{closure:/my app/x.php:3}:0:1' => []]]);
    }

    public function testReadsPrefixedClosureNameWithSpace(): void
    {
        $trace = "0 App\\{closure:/my app/x.php:3} /my app/x.php:4\n1 <main> /my app/x.php:9\n\n";

        $profile = (new ProfileBuilder())->phpspy($trace);

        Assert::same(self::shape($profile), ['main():0:1' => ['App\{closure:/my app/x.php:3}:0:1' => []]]);
    }

    public function testTellsWhyTraceWithoutEndOfSampleHasNoSamples(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('a sample ends with a blank line');

        (new ProfileBuilder())->phpspy("0 foo /app/a.php:1\n1 <main> /app/a.php:2\n");
    }

    public function testRejectsTraceOverSampleLimit(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('The trace has more than 2 samples.');

        (new ProfileBuilder(maxSamples: 2))->phpspy(\str_repeat("0 a /x:1\n\n", 3));
    }

    public function testRejectsTraceOverFrameLimit(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('The samples have more than 3 frames.');

        (new ProfileBuilder(maxFrames: 3))->phpspy("0 a /x:1\n1 b /x:1\n\n0 a /x:1\n1 b /x:1\n\n");
    }

    #[DataSet(["0 a /x:1\n\n\n\n"], 'blank lines')]
    #[DataSet(["0 a /x:1\n\n# uri = /\n# uri = /\n"], 'metadata')]
    #[DataSet(["0 a /x:1\t\n\t# uri = /\t# uri = /\t\n"], 'single-line records')]
    public function testRejectsTraceOverRecordLimit(string $trace): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('The profile has more than 3 lines or records.');

        (new ProfileBuilder(maxRecords: 3))->phpspy($trace);
    }

    public function testRejectsCollapsedOverLineLimit(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('The profile has more than 3 lines or records.');

        (new ProfileBuilder(maxRecords: 3))->collapsed("a 1\n\n\n\n");
    }

    public function testRejectsCollapsedOverFrameLimit(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('The stacks have more than 3 frames.');

        (new ProfileBuilder(maxFrames: 3))->collapsed("a;b 1\na;b 1\n");
    }

    public function testTakesFileRootOfCollapsedAsMain(): void
    {
        // As Excimer's `getCollapsedData()` names file-scope code
        $profile = (new ProfileBuilder())->collapsed("/app/index.php;foo 2\n/app/index.php 1\n");

        Assert::same(self::shape($profile), ['main():0:3' => ['foo:0:2' => []]]);
    }

    public function testMeasuresCpuTimeOfCollapsedOnCpuClock(): void
    {
        $profile = (new ProfileBuilder())->collapsed("main;foo 2\n", period: 0.001, cpuClock: true);

        $root = $profile->calls->roots()[0]->cost;
        Assert::same([$root->wt, $root->cpu, $root->ct], [2000, 2000, 2]);
        Assert::same($profile->metadata['clock'], 'cpu');
    }

    public function testRejectsSampleOverDepthLimit(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('A sample has more than 2 frames.');

        (new ProfileBuilder(maxDepth: 2))->phpspy("0 a /x:1\n1 b /x:1\n2 c /x:1\n\n");
    }

    public function testRejectsChunksOverDepthLimitWhenJoined(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('A sample has more than 2 frames.');

        (new ProfileBuilder(maxDepth: 2))->phpspy("0 a /x:1\n1 b /x:1\n# trace_id = 0.0~\n\n2 c /x:1\n# trace_id = 0.1!\n\n");
    }

    public function testRejectsTooManyUnfinishedChunks(): void
    {
        $trace = '';
        for ($i = 0; $i < 21; ++$i) {
            $trace .= "# trace_id = $i.0~\n\n";
        }

        Expect::exception(\InvalidArgumentException::class)
            ->withMessage('More than 20 frames wait in the chunks of unfinished samples.');

        (new ProfileBuilder(maxDepth: 2))->phpspy($trace);
    }

    public function testRejectsTraceOverFunctionLimit(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('The profile has more than 2 functions.');

        (new ProfileBuilder(maxFunctions: 2))->phpspy("0 a /x:1\n\n0 b /x:1\n\n0 a /y:1\n\n");
    }

    public function testRejectsCollapsedOverLimits(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('Line 2 has more than 2 frames.');

        (new ProfileBuilder(maxDepth: 2))->collapsed("a;b 1\na;b;c 1\n");
    }

    public function testRejectsCollapsedOverFunctionLimit(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('The profile has more than 2 functions.');

        (new ProfileBuilder(maxFunctions: 2))->collapsed("a;b 1\nc 1\n");
    }

    public function testDecodesContentOfMaxSize(): void
    {
        Assert::same((new ProfileBuilder(maxSize: 5))->decode('abcde'), 'abcde');
    }

    public function testRejectsContentOverMaxSize(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('The profile is larger than 4 bytes.');

        (new ProfileBuilder(maxSize: 4))->decode("a 1\n\n");
    }

    public function testRejectsCompressedContentOverMaxSizeBeforeInflating(): never
    {
        $content = \gzencode(\str_repeat('a', 1000));

        Expect::exception(\InvalidArgumentException::class)->withMessage('The profile is larger than 8 bytes.');

        (new ProfileBuilder(maxSize: 8))->decode($content);
    }

    #[DataSet([0.0], 'zero')]
    #[DataSet([-0.01], 'negative')]
    #[DataSet([\INF], 'infinite')]
    #[DataSet([\NAN], 'not a number')]
    public function testRejectsInvalidPeriodOfPhpspy(float $period): never
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessage('The sampling period must be a positive number of seconds.');

        (new ProfileBuilder())->phpspy("0 foo /app/a.php:1\n\n", period: $period);
    }

    #[DataSet([0.0], 'zero')]
    #[DataSet([-\INF], 'negative infinite')]
    public function testRejectsInvalidPeriodOfCollapsed(float $period): never
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessage('The sampling period must be a positive number of seconds.');

        (new ProfileBuilder())->collapsed("main;foo 1\n", period: $period);
    }

    public function testKeepsMetadataAndNameTagGiven(): void
    {
        $profile = (new ProfileBuilder())->collapsed(
            "main;foo 1\n",
            metadata: ['app_name' => 'app', 'profile_type' => 'calls'],
            tags: ['env' => 'dev'],
            name: 'checkout',
        );
        $named = (new ProfileBuilder())->phpspy("0 foo /app/a.php:1\n\n", tags: ['name' => 'tagged'], name: 'checkout');

        Assert::same($profile->metadata, ['app_name' => 'app', 'profile_type' => 'sampled']);
        Assert::same($profile->tags, ['env' => 'dev', 'name' => 'checkout']);
        Assert::same($named->tags, ['name' => 'tagged']);
    }

    public function testDatesProfile(): void
    {
        $date = new \DateTimeImmutable('2026-11-26 12:00:00');

        Assert::same((new ProfileBuilder())->phpspy("0 foo /app/a.php:1\n\n", $date)->date, $date);
        Assert::same((new ProfileBuilder())->collapsed("foo 1\n", $date)->date, $date);
    }

    public function testTakesResentChunkInPlaceOfFormer(): void
    {
        $trace = "0 former /x:1\n# trace_id = 1.0~\n0 resent /x:1\n# trace_id = 1.0~\n1 <main> /x:2\n# trace_id = 1.1!\n\n";

        $profile = (new ProfileBuilder())->phpspy($trace);

        Assert::same(self::shape($profile), ['main():0:1' => ['resent:0:1' => []]]);
    }

    public function testDropsSampleWithChunkMissingBeforeLastIndex(): void
    {
        // Chunks 1 and 2 add up to the count of a two-chunk sample ending at 1, but chunk 0 is lost
        $trace = "0 lost /x:1\n# trace_id = 1.2~\n1 lost /x:2\n# trace_id = 1.1!\n\n0 kept /x:1\n\n";

        $profile = (new ProfileBuilder())->phpspy($trace);

        Assert::same(self::shape($profile), ['kept:0:1' => []]);
    }

    #[DataSet(["# trace_id = 1.0?"], 'unknown end')]
    #[DataSet(["# trace_id = 1~"], 'no chunk index')]
    #[DataSet(["# trace_id = 1.0~ "], 'text after end')]
    #[DataSet(["# uri = /a # trace_id = 1.0~"], 'text before')]
    public function testSkipsMalformedChunkTrailer(string $trailer): void
    {
        $trace = "0 foo /x:1\n$trailer\n1 <main> /x:2\n\n";

        $profile = (new ProfileBuilder())->phpspy($trace);

        Assert::same(self::shape($profile), ['main():0:1' => ['foo:0:1' => []]]);
    }

    public function testFreesPendingFramesOfFinishedSamples(): void
    {
        $trace = '';
        for ($i = 0; $i < 30; ++$i) {
            $trace .= "0 a /x:1\n1 b /x:1\n# trace_id = $i.0~\n0 c /x:1\n# trace_id = $i.1!\n\n";
        }

        $profile = (new ProfileBuilder(maxDepth: 3))->phpspy($trace);

        Assert::same(self::shape($profile), ['c:0:30' => ['b:0:30' => ['a:0:30' => []]]]);
    }

    public function testCountsResentChunkOnceInPendingFrames(): void
    {
        $trace = \str_repeat("0 a /x:1\n1 b /x:1\n# trace_id = 1.0~\n", 30) . "# trace_id = 1.1!\n\n";

        $profile = (new ProfileBuilder(maxDepth: 2))->phpspy($trace);

        Assert::same(self::shape($profile), ['b:0:1' => ['a:0:1' => []]]);
    }

    public function testAcceptsUnfinishedChunksUpToLimit(): void
    {
        $trace = '';
        for ($i = 0; $i < 20; ++$i) {
            $trace .= "# trace_id = $i.0~\n\n";
        }

        $profile = (new ProfileBuilder(maxDepth: 2))->phpspy($trace . "0 a /x:1\n\n");

        Assert::same(self::shape($profile), ['a:0:1' => []]);
    }

    public function testRejectsFramesOfUnfinishedChunksOverLimit(): never
    {
        $trace = '';
        for ($i = 0; $i < 10; ++$i) {
            $trace .= "0 a /x:1\n1 b /x:1\n# trace_id = $i.0~\n\n";
        }

        Expect::exception(\InvalidArgumentException::class)
            ->withMessage('More than 20 frames wait in the chunks of unfinished samples.');

        (new ProfileBuilder(maxDepth: 2))->phpspy($trace . "0 a /x:1\n# trace_id = 10.0~\n\n");
    }

    public function testNamesFrameWithoutFunctionUnknown(): void
    {
        $profile = (new ProfileBuilder())->phpspy("0  /app/a.php:1\n1 <main> /app/a.php:2\n\n");

        Assert::same(self::shape($profile), ['main():0:1' => ['(unknown):0:1' => []]]);
    }

    #[DataSet(
        ["0 f <internal>:-1\n\n0 f /a.php:1\n\n0 f /b.php:1\n\n", ['main():0:3' => ['f:0:1' => [], 'f (/a.php):0:1' => [], 'f (/b.php):0:1' => []]]],
        'two files and none',
    )]
    #[DataSet(["0 f <internal>:-1\n\n0 f <elided>:-1\n\n0 f :1\n\n0 f /a.php:1\n\n", ['f:0:4' => []]], 'one file and none')]
    public function testTellsFunctionsApartOnlyByRealFiles(string $trace, array $shape): void
    {
        $profile = (new ProfileBuilder())->phpspy($trace);

        Assert::same(self::shape($profile), $shape);
    }

    public function testTakesFileScopeOfIncludedFilesUnderMain(): void
    {
        $trace = "0 <main> /app/a.php:1\n\n0 <main> /app/b.php:1\n\n";

        $profile = (new ProfileBuilder())->phpspy($trace);

        Assert::same(self::shape($profile), ['main():0:2' => ['<main> (/app/a.php):0:1' => [], '<main> (/app/b.php):0:1' => []]]);
    }

    #[DataSet(["0 a /x:1\n\n0 b /x:1\n\n0 a /x:1\n\n", new ProfileBuilder(maxFunctions: 2)], 'functions')]
    #[DataSet(["0 a /x:1\n1 b /x:1\n\n", new ProfileBuilder(maxDepth: 2)], 'depth')]
    #[DataSet(["0 a /x:1\n\n0 a /x:1\n\n", new ProfileBuilder(maxSamples: 2)], 'samples')]
    #[DataSet(["0 a /x:1\n1 a /x:1\n\n0 a /x:1\n1 a /x:1\n\n", new ProfileBuilder(maxFrames: 4)], 'frames')]
    #[DataSet(["0 a /x:1\n\n", new ProfileBuilder(maxRecords: 2)], 'records')]
    #[DataSet(["0 a /x:1\t\n\n", new ProfileBuilder(maxRecords: 3)], 'single-line records')]
    public function testAcceptsTraceAtLimit(string $trace, ProfileBuilder $builder): void
    {
        $profile = $builder->phpspy($trace);

        Assert::same($profile->peaks->ct, \substr_count($trace, "\n\n"));
    }

    #[DataSet(["a;b 1\na 1\n", new ProfileBuilder(maxFunctions: 2)], 'functions')]
    #[DataSet(["a;b 2\n", new ProfileBuilder(maxDepth: 2)], 'depth')]
    #[DataSet(["a;b 1\na;b 1\n", new ProfileBuilder(maxFrames: 4)], 'frames')]
    #[DataSet(["a 1\na 1\n", new ProfileBuilder(maxRecords: 2)], 'lines')]
    public function testAcceptsCollapsedAtLimit(string $content, ProfileBuilder $builder): void
    {
        $profile = $builder->collapsed($content);

        Assert::same($profile->peaks->ct, 2);
    }

    public function testReadsCollapsedLastLineWithoutLineBreak(): void
    {
        $profile = (new ProfileBuilder())->collapsed("main;foo 1\nmain;bar 3");

        Assert::same(self::shape($profile), ['main():0:4' => ['foo:0:1' => [], 'bar:0:3' => []]]);
    }

    public function testReadsCollapsedFramesWithSpacesAndBlankLines(): void
    {
        $profile = (new ProfileBuilder())->collapsed("my app;do it  3\n   \n\t\nmy app;do it\t2\r\n");

        Assert::same(self::shape($profile), ['my app:0:5' => ['do it:0:5' => []]]);
    }

    public function testSumsCollapsedCountsUpToExactFloats(): void
    {
        $profile = (new ProfileBuilder())->collapsed("a 4503599627370496\nb 4503599627370496\n");

        Assert::same($profile->peaks->ct, 2 ** 53);
    }

    public function testRejectsCollapsedCountsPastExactFloats(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('The counts are too large.');

        (new ProfileBuilder())->collapsed("a 4503599627370497\nb 4503599627370496\n");
    }

    public function testCutsLongLineInError(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage(
            'Line 2 is not a phpspy frame `<depth> <function> <file>:<line>`: `a' . \str_repeat('z', 76) . '...`.',
        );

        (new ProfileBuilder())->phpspy("0 a /x:1\na" . \str_repeat('z', 200) . "\n\n");
    }

    public function testCutsLongCollapsedLineInError(): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage(
            'Line 3 is not a collapsed stack `frame;frame;frame count`: `' . \str_repeat('z', 77) . '...`.',
        );

        (new ProfileBuilder())->collapsed("a 1\n\n" . \str_repeat('z', 200) . "\n");
    }

    public function testCompactsPhpspyTraceOverNodeLimit(): void
    {
        $trace = '';
        for ($i = 0; $i < 30; ++$i) {
            $trace .= "0 f$i /x:1\n1 <main> /x:1\n\n";
        }

        $profile = (new ProfileBuilder(maxNodes: 5))->phpspy($trace);

        Assert::same($profile->metadata['compacted'], [
            'merged' => 0,
            'collapsed' => 0,
            'pruned' => 27,
            'truncated' => 0,
            'folded' => 90.0,
            'limit' => 5,
        ]);
        Assert::same($profile->peaks->ct, 30);
    }

    public function testReportsLineOfRecordInSingleLineMode(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessageContaining('Line 2 is not a phpspy frame');

        (new ProfileBuilder())->phpspy("0 a /x:1\t\n0 b /x:1\tbad\t\n");
    }

    public function testTakesMetadataWithTabAndDigitsInDefaultMode(): void
    {
        // Only the first line with a frame tells the mode
        $trace = "0 foo /app/a.php:1\n# varpeek q@/app/a.php:1 = a\t1 b\t\n1 <main> /app/a.php:2\n\n";

        $profile = (new ProfileBuilder())->phpspy($trace);

        Assert::same(self::shape($profile), ['main():0:1' => ['foo:0:1' => []]]);
    }

    public function testTellsSingleLineModeAfterLineOfMetadata(): void
    {
        $trace = "# pid = 1\t\n0 foo /app/a.php:1\t1 <main> /app/a.php:2\t\n";

        $profile = (new ProfileBuilder())->phpspy($trace);

        Assert::same(self::shape($profile), ['main():0:1' => ['foo:0:1' => []]]);
    }

    public function testKeepsCountingPendingFramesOfOtherSamplesWhenOneFinishes(): void
    {
        $trace = '';
        for ($i = 0; $i < 18; ++$i) {
            $trace .= "# trace_id = $i.0~\n\n";
        }
        $trace .= "0 a /x:1\n# trace_id = 100.0~\n\n1 b /x:1\n# trace_id = 100.1!\n\n";
        for ($i = 18; $i < 21; ++$i) {
            $trace .= "# trace_id = $i.0~\n\n";
        }

        Expect::exception(\InvalidArgumentException::class)
            ->withMessage('More than 20 frames wait in the chunks of unfinished samples.');

        (new ProfileBuilder(maxDepth: 2))->phpspy($trace);
    }

    #[DataSet(["0 a /x:1\n\n0 a /x:1\n1 b /x:1\n2 c /x:1\n"], 'cut off')]
    #[DataSet(["0 a /x:1\n\n0 a /x:1\n1 b /x:1\n2 c /x:1\n# trace_id = 1.0~\n\n"], 'unfinished chunk')]
    public function testRejectsDeepSampleWithoutEnd(string $trace): never
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('A sample has more than 2 frames.');

        (new ProfileBuilder(maxDepth: 2))->phpspy($trace);
    }

    public function testReadsNumericFunctionAndFileNames(): void
    {
        $profile = (new ProfileBuilder())->phpspy("0 123 42:1\n1 <main> /x:2\n\n");

        Assert::same(self::shape($profile), ['main():0:1' => ['123:0:1' => []]]);
    }

    public function testMeasuresWallTimeUpToExactFloats(): void
    {
        $profile = (new ProfileBuilder())->collapsed("a 9007199254740992\n", period: 1e-6);

        Assert::same($profile->peaks->wt, 2 ** 53);
    }

    public function testRejectsWallTimeOfAllRootsPastExactFloats(): never
    {
        Expect::exception(\InvalidArgumentException::class)
            ->withMessage('The wall time is too large: the `period` is too long.');

        (new ProfileBuilder())->collapsed("a 1\nb 1\n", period: 2 ** 53 / 1e6);
    }

    public function testLetsOtherFibersRunDuringBuild(): void
    {
        $trace = \str_repeat("0 foo /x:1\n1 <main> /x:2\n\n", 50);
        $builds = [
            static fn(): Profile => (new ProfileBuilder())->phpspy($trace, timeSlice: new TimeSlice(0.0, 1)),
            static fn(): Profile => (new ProfileBuilder())->collapsed("<main>;foo 1\n", timeSlice: new TimeSlice(0.0, 1)),
        ];
        $suspended = [];
        $profiles = [];

        foreach ($builds as $build) {
            $fiber = new \Fiber($build);
            $fiber->start();
            $count = 0;
            while (!$fiber->isTerminated()) {
                ++$count;
                $fiber->resume();
            }
            $suspended[] = $count > 0;
            $profiles[] = self::shape($fiber->getReturn());
        }

        Assert::same($suspended, [true, true]);
        Assert::same($profiles, [['main():0:50' => ['foo:0:50' => []]], ['main():0:1' => ['foo:0:1' => []]]]);
    }

    /**
     * @return array<string, array> Call tree as `name:wt:ct` keys.
     */
    private static function shape(Profile $profile): array
    {
        $map = static function (array $branches) use (&$map): array {
            $result = [];
            /** @var Branch<Edge> $branch */
            foreach ($branches as $branch) {
                $cost = $branch->item->cost;
                $result["{$branch->item->callee}:{$cost->wt}:{$cost->ct}"] = $map($branch->children);
            }
            return $result;
        };

        return $map(\array_values($profile->tree()->root));
    }
}
