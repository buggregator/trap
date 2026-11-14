<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Client\Profiler\Internal\Model;

use Buggregator\Trap\Client\Profiler\Internal\Model\SpxReport;
use Buggregator\Trap\Client\Profiler\Internal\Warnings;
use Buggregator\Trap\Support\Spx\Archive;
use Buggregator\Trap\Tests\Unit\Client\Profiler\Stub\LogCapture;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(SpxReport::class)]
final class SpxReportTest
{
    private const KEY = 'spx-full-20250518_120000-app-42-1804289383';
    private const JSON = '{"enabled_metrics": ["wt", "ct"], "called_function_count": 3, "recorded_call_count": 3}';

    private string $dir = '';
    private LogCapture $log;

    #[BeforeTest]
    public function createDir(): void
    {
        $this->dir = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . \uniqid('trap-spx-report-', true);
        \mkdir($this->dir);
        Warnings::reset();
        Warnings::$logger = $this->log = new LogCapture();
    }

    #[AfterTest]
    public function removeDir(): void
    {
        Warnings::$logger = null;
        Warnings::reset();
        foreach ($this->files() as $file) {
            \unlink("{$this->dir}/$file");
        }
        \rmdir($this->dir);
    }

    public function testCountsSelfTimesOfCalls(): void
    {
        // `foo` takes 10.6 and 4.6, `bar` ends before it starts by the clock: its self time is none
        $this->write(self::JSON, "[events]\n0 1 0 0\n1 1 10 5\n1 0 20.6 9.6\n2 1 25 12\n2 0 24 11\n0 0 30.6 20\n"
            . "[functions]\nmain\nfoo\nbar\n");
        $report = SpxReport::read($this->dir, self::KEY, keep: true);

        $costs = [];
        $report->tree()->walk(static function (int $id, ?int $parent, string $name, ?string $file, int $wt, int $cpu, int $samples) use (&$costs): void {
            $costs[$name] = [$wt, $cpu, $samples];
        });

        Assert::same($costs, [
            'main()' => [21, 16, 1],
            'foo' => [11, 5, 1],
            'bar' => [0, 0, 1],
        ]);
        Assert::same($report->tree(), $report->tree());
    }

    public function testArchivesCustomMetadataAsPrettyJson(): void
    {
        $this->write(self::JSON, "[events]\n[functions]\na\nb\nc\n");
        $report = SpxReport::read($this->dir, self::KEY, keep: true);

        [$size, $chunks] = $report->archive("release=é\xff");
        $files = self::unpack(\implode('', \iterator_to_array($chunks, false)));

        $json = $files[self::KEY . '.json'];
        Assert::string($json)->startsWith("{\n")->endsWith("}\n")->contains('"custom_metadata_str": "release=é' . "\u{FFFD}" . '"');
        Assert::same($files[self::KEY . '.txt.gz'], (string) \file_get_contents("{$this->dir}/" . self::KEY . '.txt.gz'));
        Assert::same($size, \strlen(\implode('', \iterator_to_array($report->archive("release=é\xff")[1], false))));
    }

    public function testArchivesEmptyTrace(): void
    {
        $this->write(self::JSON, '');
        $report = SpxReport::read($this->dir, self::KEY, keep: true);

        [$size, $chunks] = $report->archive();
        $archive = \implode('', \iterator_to_array($chunks, false));

        Assert::same(\strlen($archive), $size);
        Assert::same(self::unpack($archive)[self::KEY . '.txt.gz'], '');
    }

    public function testPutsBackUndeliveredReportOnceWithNewerModificationTime(): void
    {
        $this->write(self::JSON, "[events]\n[functions]\na\nb\nc\n");
        $later = \time() + 100;
        \touch("{$this->dir}/" . self::KEY . '.txt.gz', $later);
        $report = SpxReport::read($this->dir, self::KEY, keep: false);

        $warning = $report->settle(false);
        unset($report);

        \clearstatcache();
        Assert::string($warning)->contains('is not delivered, and is kept');
        Assert::same(\filemtime("{$this->dir}/" . self::KEY . '.txt.gz'), $later + 1);
        Assert::same($this->files(), [self::KEY . '.json', self::KEY . '.txt.gz']);
        Assert::same($this->log->records, []);
    }

    public function testDeletesDeliveredReportQuietly(): void
    {
        $this->write(self::JSON, "[events]\n[functions]\na\nb\nc\n");
        $report = SpxReport::read($this->dir, self::KEY, keep: false);

        $report->settle(true);
        unset($report);

        Assert::same($this->files(), []);
        Assert::same($this->log->records, []);
    }

    /**
     * @return array<string, string>
     */
    private static function unpack(string $archive): array
    {
        $offset = 0;
        $read = static function (int $length) use ($archive, &$offset): string {
            $data = (string) \substr($archive, $offset, $length);
            $offset += $length;
            return $data;
        };

        $files = [];
        while (($entry = Archive::next($read)) !== null) {
            [$name, $size] = $entry;
            $files[$name] = $read($size);
            $read(Archive::paddingOf($size));
        }

        return $files;
    }

    private function write(string $json, string $trace): void
    {
        \file_put_contents("{$this->dir}/" . self::KEY . '.json', $json);
        \file_put_contents("{$this->dir}/" . self::KEY . '.txt.gz', $trace === '' ? '' : \gzencode($trace, 1));
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $files = \array_values(\array_diff((array) \scandir($this->dir), ['.', '..']));
        \sort($files);

        return $files;
    }
}
