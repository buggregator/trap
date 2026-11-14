<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Client\Profiler;

use Buggregator\Trap\Client\Profiler\Format;
use Buggregator\Trap\Client\Profiler\Internal\Model\SpeedscopeWriter;
use Buggregator\Trap\Client\Profiler\Internal\Model\SpxReport;
use Buggregator\Trap\Client\Profiler\Internal\Recorded;
use Buggregator\Trap\Client\Profiler\Metadata;
use Buggregator\Trap\Client\Profiler\Spx;
use Buggregator\Trap\Support\Spx\Archive;
use Buggregator\Trap\Support\Spx\Metadata as SpxMetadata;
use Buggregator\Trap\Tests\Unit\Client\Profiler\Stub\PprofDecoder;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

/**
 * Conversions of the reports of `tests/Stub/spx/real`, recorded with ext-spx.
 */
#[Test]
#[Covers(Spx::class)]
#[Covers(SpxReport::class)]
#[Covers(SpeedscopeWriter::class)]
final class SpxRealReportTest
{
    private const REAL = __DIR__ . '/../../../Stub/spx/real';

    private string $dir = '';

    #[BeforeTest]
    public function createDir(): void
    {
        $this->dir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . \uniqid('trap-spx-real-', true);
        \mkdir($this->dir);
    }

    #[AfterTest]
    public function removeDir(): void
    {
        \array_map(\unlink(...), (array) \glob($this->dir . '/*'));
        \rmdir($this->dir);
    }

    public function convertsToXHProfWithEveryCall(): void
    {
        $xhprof = \json_decode($this->recorded('default')->encode(Format::XHProf), true, flags: \JSON_THROW_ON_ERROR);
        $metadata = self::metadata('default');

        // The file of the script is `main()`; an included file stays a file
        Assert::same(\array_keys($xhprof), [
            'main()',
            'main()==>/work/lib.php',
            'main()==>run',
            'run==>fib',
            'fib==>fib@1',
            'fib@1==>fib@2',
            'fib@2==>fib@3',
            'fib@3==>fib@4',
            'run==>{closure}',
            'run==>lib',
            'run==>Greeter::greet',
        ]);
        Assert::same(\array_sum(\array_column($xhprof, 'ct')), $metadata->callCount);
        Assert::same([$xhprof['fib==>fib@1']['ct'], $xhprof['fib@1==>fib@2']['ct'], $xhprof['fib@3==>fib@4']['ct']], [2, 4, 2]);
        Assert::true(\abs($xhprof['main()']['wt'] - (int) $metadata->int('wall_time_ms')) <= 1);
    }

    public function convertsReportOfSeveralRootsWithCpuTime(): void
    {
        $xhprof = \json_decode($this->recorded('metrics')->encode(Format::XHProf), true, flags: \JSON_THROW_ON_ERROR);

        Assert::same($xhprof['main()']['ct'], 1);
        Assert::true(isset($xhprof['main()==>::zend_compile_file'], $xhprof['main()==>/work/calls.php'], $xhprof['main()==>::php_request_shutdown']));
        Assert::same($xhprof['/work/calls.php==>run']['ct'], 1);
        Assert::same($xhprof['Greeter::greet==>strtoupper']['ct'], 1);
        foreach ($xhprof as $call => $cost) {
            Assert::true($cost['cpu'] <= $cost['wt'] + 1, $call);
        }
        Assert::true($xhprof['main()']['cpu'] > 0);
    }

    public function convertsToSpeedscopeAndCollapsedOverTheWholeRun(): void
    {
        $recorded = $this->recorded('shutdown');
        $speedscope = \json_decode($recorded->encode(Format::Speedscope), true, flags: \JSON_THROW_ON_ERROR);
        $collapsed = $recorded->encode(Format::Collapsed);
        $wallTime = (int) self::metadata('shutdown')->int('wall_time_ms');

        Assert::same($speedscope['shared']['frames'][0], ['name' => 'main()']);
        Assert::true(\in_array(['name' => '/work/shutdown.php', 'file' => '/work/shutdown.php'], $speedscope['shared']['frames'], true));
        // SPX takes the wall time when it finalizes the report, a little after the last call ends
        $weights = \array_sum($speedscope['profiles'][0]['weights']) / 1000;
        Assert::true($weights <= $wallTime && $weights > $wallTime - 10);
        Assert::string($collapsed)
            ->contains("\nmain();::php_request_shutdown;{closure};cleanup ")
            ->contains("\nmain();/work/shutdown.php;register_shutdown_function ");
    }

    public function convertsToPprofWithTheRunTimes(): void
    {
        $metadata = self::metadata('sampling');

        $pprof = PprofDecoder::profile($this->recorded('sampling', 500)->encode(Format::Pprof));

        Assert::same($pprof['sample_type'], [['sampled_calls', 'count'], ['wall', 'nanoseconds']]);
        Assert::same($pprof['period'], 500_000);
        Assert::same($pprof['time_nanos'], (int) $metadata->int('exec_ts') * 1_000_000_000);
        Assert::same($pprof['duration_nanos'], (int) $metadata->int('wall_time_ms') * 1000);
    }

    public function packsTheReportWithNameAndTagsForTheSpxWebUi(): void
    {
        $key = (string) self::metadata('http')->string('key');
        [, $chunks] = $this->recorded('http')->stream(Format::Spx, new Metadata(
            name: 'checkout',
            tags: ['env' => 'test'],
            appName: null,
            hostname: 'test',
            date: new \DateTimeImmutable(),
            file: null,
            line: null,
            driver: 'spx',
            options: [],
            duration: 0.01,
            peakMemory: 0,
        ));

        $files = self::unpack(\implode('', \iterator_to_array($chunks, false)));

        Assert::same(\array_keys($files), ["$key.json", "$key.txt.gz"]);
        Assert::same($files["$key.txt.gz"], \file_get_contents(self::REAL . '/http.txt.gz'));
        // Written anew, the metadata holds the rest as SPX wrote it, the raw control character escaped
        $metadata = SpxMetadata::fromJson($files["$key.json"]);
        Assert::same($metadata->string('custom_metadata_str'), 'name=checkout, env=test');
        $expected = self::metadata('http')->data;
        $expected['custom_metadata_str'] = 'name=checkout, env=test';
        Assert::same($metadata->data, $expected);
    }

    private static function metadata(string $report): SpxMetadata
    {
        return SpxMetadata::fromJson((string) \file_get_contents(self::REAL . "/$report.json"));
    }

    /**
     * @return array<string, string> Contents by names.
     */
    private static function unpack(string $archive): array
    {
        $offset = 0;
        $read = static function (int $length) use ($archive, &$offset): string {
            $data = \substr($archive, $offset, $length);
            $offset += $length;
            return $data;
        };

        $files = [];
        while (($entry = Archive::next($read)) !== null) {
            $files[$entry[0]] = $read($entry[1]);
            $read(Archive::paddingOf($entry[1]));
        }

        return $files;
    }

    /**
     * The report put in the data directory under its key, as SPX names it.
     */
    private function recorded(string $report, ?int $samplingPeriod = null): Recorded
    {
        $key = (string) self::metadata($report)->string('key');
        \copy(self::REAL . "/$report.json", "{$this->dir}/$key.json");
        \copy(self::REAL . "/$report.txt.gz", "{$this->dir}/$key.txt.gz");

        return Spx::recorded(SpxReport::read($this->dir, $key, keep: true), $samplingPeriod);
    }
}
