<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\FilesObserver;

use Buggregator\Trap\Module\Profiler\FilesObserver\SPX;
use Buggregator\Trap\Module\Profiler\Frame;
use Buggregator\Trap\Module\Profiler\SPX\ProfileBuilder;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Server\Service\FilesObserver\FileInfo;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Covers(SPX::class)]
#[Test]
final class SPXTest
{
    private const STUB = __DIR__ . '/../../../../Stub/spx/handwritten';
    private const KEY = 'spx-full-20250518_120000-app-42-1804289383';
    private const REAL = __DIR__ . '/../../../../Stub/spx/real';

    private string $dir;

    public function testAcceptsTracesOfFullReports(): void
    {
        $converter = self::converter();

        Assert::same($converter->validate(new FileInfo('/tmp/spx/' . self::KEY . '.txt.gz', 1, 0, 0)), true);
        Assert::same($converter->validate(new FileInfo('/tmp/spx/' . self::KEY . '.json', 1, 0, 0)), false);
        Assert::same($converter->validate(new FileInfo('/tmp/spx/cachegrind.out.1.gz', 1, 0, 0)), false);
    }

    #[BeforeTest]
    public function createDir(): void
    {
        $this->dir = \sys_get_temp_dir() . '/' . \uniqid('trap-spx-', true);
        \mkdir($this->dir);
    }

    #[AfterTest]
    public function removeDir(): void
    {
        \array_map(\unlink(...), (array) \glob($this->dir . '/*'));
        \rmdir($this->dir);
    }

    public function testConvertsReport(): void
    {
        $path = $this->writeReport();
        $file = self::info($path);

        $frames = \iterator_to_array(self::converter()->convert($file), false);

        Assert::count($frames, 1);
        Assert::instanceOf($frames[0], Frame::class);
        Assert::same($frames[0]->payload->type, Frame\Type::SPX);
        $profile = $frames[0]->payload->getProfile();
        Assert::same($profile->date->getTimestamp(), 1747569600);
        Assert::same($profile->metadata['filename'], self::KEY . '.txt.gz');
        Assert::same($profile->metadata['filesize'], $file->size);
        Assert::same($profile->metadata['hostname'], 'app');
        Assert::same(\count($profile->tree()), 6);
    }

    public function testReportIsReadyWhenTraceStreamEnds(): void
    {
        $data = (string) \gzencode((string) \file_get_contents(self::STUB . '.txt'));
        $path = $this->dir . '/' . self::KEY . '.txt.gz';
        $converter = self::converter();

        \file_put_contents($path, \substr($data, 0, \intdiv(\strlen($data), 2)));
        Assert::same($converter->isReady(self::info($path)), false);

        // SPX writes the metadata when profiling ends, and closes the trace stream after that
        \copy(self::STUB . '.json', $this->dir . '/' . self::KEY . '.json');
        \file_put_contents($path, \substr($data, \intdiv(\strlen($data), 2)), \FILE_APPEND);
        Assert::same($converter->isReady(self::info($path)), true);
    }

    public function testOldReportWithMetadataIsCompleteUninflated(): void
    {
        // A stream that doesn't end: inflated, it would not be complete
        $data = (string) \gzencode((string) \file_get_contents(self::STUB . '.txt'));
        $path = $this->dir . '/' . self::KEY . '.txt.gz';
        \file_put_contents($path, \substr($data, 0, \intdiv(\strlen($data), 2)));
        \touch($path, \time() - 60);
        $converter = self::converter();

        Assert::same($converter->isReady(self::info($path)), false);

        \copy(self::STUB . '.json', $this->dir . '/' . self::KEY . '.json');
        Assert::same($converter->isReady(self::info($path)), true);
    }

    public function testTakesNoReportWithoutZlib(): void
    {
        $output = new BufferedOutput();
        $converter = new SPX(new Logger($output), new ProfileBuilder(), zlib: false);

        Assert::same($converter->validate(new FileInfo('/tmp/spx/' . self::KEY . '.txt.gz', 1, 0, 0)), false);
        Assert::string($output->fetch())->contains('SPX: the PHP of Trap has no ext-zlib, so SPX reports are not read.');
    }

    public function testTraceOverSizeLimitIsReadyUnreadAndSkippedWithWarning(): void
    {
        $path = $this->dir . '/' . self::KEY . '.txt.gz';
        // Not even gzip: it is not read
        \file_put_contents($path, \str_repeat('x', 100));
        $output = new BufferedOutput();
        $converter = new SPX(new Logger($output), new ProfileBuilder(maxSize: 16));

        Assert::same($converter->isReady(self::info($path)), true);
        Assert::same(\iterator_to_array($converter->convert(self::info($path)), false), []);
        Assert::string($output->fetch())->contains('SPX: ' . self::KEY . '.txt.gz is skipped: the trace is larger than 16 bytes.');
    }

    /**
     * Once the trace ends, the metadata is there or never will be: the report is skipped, not followed forever.
     */
    #[DataSet([null, 'Unable to read the report metadata'], 'missing')]
    #[DataSet(['{"key": "spx-full-', 'Invalid metadata JSON'], 'broken')]
    public function testSkipsReportWithoutValidMetadata(?string $json, string $error): void
    {
        $path = $this->writeReport();
        $json === null
            ? \unlink($this->dir . '/' . self::KEY . '.json')
            : \file_put_contents($this->dir . '/' . self::KEY . '.json', $json);
        $output = new BufferedOutput();
        $converter = new SPX(new Logger($output), new ProfileBuilder());

        Assert::same($converter->isReady(self::info($path)), true);
        Assert::same(\iterator_to_array($converter->convert(self::info($path)), false), []);
        Assert::string($output->fetch())->contains('SPX: ' . self::KEY . '.txt.gz is skipped: ' . $error);
    }

    public function testSkipsReportRemovedBeforeItIsRead(): void
    {
        $file = self::info($this->writeReport());
        \array_map(\unlink(...), (array) \glob($this->dir . '/*'));
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERY_VERBOSE);

        $frames = \iterator_to_array((new SPX(new Logger($output), new ProfileBuilder()))->convert($file), false);

        Assert::same($frames, []);
        Assert::string($output->fetch())->contains('is removed before it is read, skipped.');
    }

    public function testTellsReportRemovedBeforeItIsShown(): never
    {
        $file = self::info($this->writeReport());
        $frames = \iterator_to_array(self::converter()->convert($file), false);
        \array_map(\unlink(...), (array) \glob($this->dir . '/*'));

        Expect::exception(\RuntimeException::class)
            ->withMessage('The SPX report ' . self::KEY . '.txt.gz is removed before it is shown.');

        $frames[0]->payload->getProfile();
    }

    public function testCorruptedTraceIsReadyToReportTheError(): void
    {
        $path = $this->dir . '/' . self::KEY . '.txt.gz';
        \file_put_contents($path, "\x1f\x8b" . \str_repeat("\xff", 64));

        Assert::same(self::converter()->isReady(self::info($path)), true);
    }

    public function testFileRemovedBeforeCheckIsNotReady(): void
    {
        Assert::same(self::converter()->isReady(new FileInfo($this->dir . '/' . self::KEY . '.txt.gz', 1, 0, 0)), false);
    }

    public function testWarnsWhenProfileIsCompacted(): void
    {
        $path = $this->writeReport();
        $output = new BufferedOutput();
        $converter = new SPX(new Logger($output), new ProfileBuilder(maxNodes: 2));

        $profile = \iterator_to_array($converter->convert(self::info($path)), false)[0]->payload->getProfile();

        Assert::true(\is_array($profile->metadata['compacted'] ?? null));
        Assert::string($output->fetch())->ignoringAnsi()
            ->contains('SPX: ' . self::KEY . '.txt.gz is compacted to 2 call tree nodes: ');
    }

    public function testRealReportIsReadyOnceSpxEndsTheTrace(): void
    {
        // A report recorded with ext-spx, cut mid-stream as SPX leaves it while it writes
        $path = $this->dir . '/' . self::KEY . '.txt.gz';
        \copy(self::REAL . '/truncated.txt.gz', $path);
        \copy(self::REAL . '/truncated.json', $this->dir . '/' . self::KEY . '.json');
        $converter = self::converter();

        Assert::same($converter->isReady(self::info($path)), false);

        $full = (string) \file_get_contents(self::REAL . '/default.txt.gz');
        \file_put_contents($path, \substr($full, (int) \filesize($path)), \FILE_APPEND);
        Assert::same($converter->isReady(self::info($path)), true);
        $profile = \iterator_to_array($converter->convert(self::info($path)), false)[0]->payload->getProfile();
        Assert::same($profile->metadata['details']['Command'], 'calls.php');
        Assert::same($profile->calls->callers('fib')[0]->caller, 'run');
    }

    private static function converter(): SPX
    {
        return new SPX(new Logger(), new ProfileBuilder());
    }

    /**
     * @param non-empty-string $path
     */
    private static function info(string $path): FileInfo
    {
        \clearstatcache(true, $path);

        return new FileInfo($path, (int) \filesize($path), 0, (int) \filemtime($path));
    }

    /**
     * @return non-empty-string Path to the trace.
     */
    private function writeReport(): string
    {
        $path = $this->dir . '/' . self::KEY . '.txt.gz';
        \file_put_contents($path, \gzencode((string) \file_get_contents(self::STUB . '.txt'), 1));
        \copy(self::STUB . '.json', $this->dir . '/' . self::KEY . '.json');

        return $path;
    }
}
