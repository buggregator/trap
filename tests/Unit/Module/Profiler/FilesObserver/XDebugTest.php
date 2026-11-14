<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\FilesObserver;

use Buggregator\Trap\Module\Profiler\FilesObserver\XDebug;
use Buggregator\Trap\Module\Profiler\Frame;
use Buggregator\Trap\Module\Profiler\XDebug\ProfileBuilder;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Server\Service\FilesObserver\FileInfo;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Covers(XDebug::class)]
#[Test]
final class XDebugTest
{
    private const STUB = __DIR__ . '/../../../../Stub/xdebug/shared.cachegrind';

    private string $dir;

    public function testAcceptsDefaultProfileNames(): void
    {
        $converter = self::converter();

        Assert::same($converter->validate(new FileInfo('/tmp/cachegrind.out.123', 1, 0, 0)), true);
        Assert::same($converter->validate(new FileInfo('/tmp/cachegrind.out.123.gz', 1, 0, 0)), true);
        Assert::same($converter->validate(new FileInfo('/tmp/trace.123.xt', 1, 0, 0)), false);
        Assert::same($converter->validate(new FileInfo('/tmp/run.app.xhprof', 1, 0, 0)), false);
    }

    #[BeforeTest]
    public function createDir(): void
    {
        $this->dir = \sys_get_temp_dir() . '/' . \uniqid('trap-xdebug-', true);
        \mkdir($this->dir);
    }

    #[AfterTest]
    public function removeDir(): void
    {
        \array_map(\unlink(...), (array) \glob($this->dir . '/*'));
        \rmdir($this->dir);
    }

    #[DataSet(['cachegrind.out.1234', false], 'plain')]
    #[DataSet(['cachegrind.out.9999.gz', true], 'gzipped')]
    public function testConvertsProfile(string $name, bool $gzip): void
    {
        $content = (string) \file_get_contents(self::STUB);
        $path = $this->dir . '/' . $name;
        \file_put_contents($path, $gzip ? \gzencode($content) : $content);
        $file = new FileInfo($path, (int) \filesize($path), 0, 1747576624);

        $frames = \iterator_to_array(self::converter()->convert($file), false);

        Assert::count($frames, 1);
        Assert::instanceOf($frames[0], Frame::class);
        Assert::same($frames[0]->payload->type, Frame\Type::XDebug);
        $profile = $frames[0]->payload->getProfile();
        Assert::same($profile->date->getTimestamp(), 1747576624);
        Assert::same($profile->metadata['filename'], $name);
        Assert::same(\count($profile->tree()), 15);
    }

    #[DataSet([false], 'plain')]
    #[DataSet([true], 'gzipped')]
    public function testProfileIsReadyOnlyWhenWrittenCompletely(bool $gzip): void
    {
        $content = (string) \file_get_contents(self::STUB);
        $data = $gzip ? (string) \gzencode($content) : $content;
        $path = $this->dir . '/cachegrind.out.1234' . ($gzip ? '.gz' : '');
        $converter = self::converter();

        // The profiled request is in progress: the blocks of the calls not ended yet are missing
        \file_put_contents($path, \substr($data, 0, \intdiv(\strlen($data), 2)));
        Assert::same($converter->isReady(self::info($path)), false);

        \file_put_contents($path, \substr($data, \intdiv(\strlen($data), 2)), \FILE_APPEND);
        Assert::same($converter->isReady(self::info($path)), true);
    }

    public function testGzipFileWrittenAnewIsReadFromStart(): void
    {
        $path = $this->dir . '/cachegrind.out.1234.gz';
        $converter = self::converter();
        $old = (string) \gzencode(self::randomProfile(1, 3000));
        \file_put_contents($path, \substr($old, 0, \intdiv(\strlen($old), 2)));
        Assert::same($converter->isReady(self::info($path)), false);

        // Another profile ends up at the same path: Xdebug reuses names of FPM workers
        \file_put_contents($path, (string) \gzencode(self::randomProfile(2, 4000)));
        Assert::same($converter->isReady(self::info($path)), true);
    }

    public function testCompleteGzipStreamWithoutSummaryIsNotReady(): void
    {
        $path = $this->dir . '/cachegrind.out.1234.gz';
        $content = (string) \file_get_contents(self::STUB);
        \file_put_contents($path, \gzencode(\substr($content, 0, (int) \strrpos($content, 'summary:'))));

        Assert::same(self::converter()->isReady(self::info($path)), false);
    }

    public function testCorruptedGzipIsReadyToReportTheError(): void
    {
        $path = $this->dir . '/cachegrind.out.1234.gz';
        \file_put_contents($path, "\x1f\x8b" . \str_repeat("\xff", 64));

        Assert::same(self::converter()->isReady(self::info($path)), true);
    }

    public function testFileRemovedBeforeCheckIsNotReady(): void
    {
        $file = new FileInfo($this->dir . '/cachegrind.out.1234', 1, 0, 0);

        Assert::same(self::converter()->isReady($file), false);
    }

    public function testWarnsWhenProfileIsCompacted(): void
    {
        $path = $this->dir . '/cachegrind.out.1234';
        \copy(self::STUB, $path);
        $output = new BufferedOutput();
        $converter = new XDebug(new Logger($output), new ProfileBuilder(maxNodes: 5));

        $profile = \iterator_to_array($converter->convert(self::info($path)), false)[0]->payload->getProfile();

        Assert::true(\is_array($profile->metadata['compacted'] ?? null));
        Assert::string($output->fetch())->ignoringAnsi()
            ->contains('XDebug: cachegrind.out.1234 is compacted to 5 call tree nodes: ');
    }

    public function testProfileWithinNodeLimitLogsNothing(): void
    {
        $path = $this->dir . '/cachegrind.out.1234';
        \copy(self::STUB, $path);
        $output = new BufferedOutput();
        $converter = new XDebug(new Logger($output), new ProfileBuilder(maxNodes: 1000));

        $profile = \iterator_to_array($converter->convert(self::info($path)), false)[0]->payload->getProfile();

        Assert::same(\array_key_exists('compacted', $profile->metadata), false);
        Assert::same($output->fetch(), '');
    }

    public function testSkipsFileOverMaxSizeUnread(): void
    {
        // A gzip stream that never ends: the file is not inflated to tell it is ready
        $path = $this->dir . '/cachegrind.out.1234.gz';
        \file_put_contents($path, \substr((string) \gzencode(\str_repeat((string) \file_get_contents(self::STUB), 100)), 0, 1000));
        $output = new BufferedOutput();
        $converter = new XDebug(new Logger($output), new ProfileBuilder(maxSize: 100));

        Assert::same($converter->isReady(self::info($path)), true);
        Assert::same(\iterator_to_array($converter->convert(self::info($path)), false), []);
        Assert::string($output->fetch())->contains('XDebug: cachegrind.out.1234.gz is skipped: it is larger than 100 bytes.');
    }

    /**
     * A profile of calls with random names: compresses poorly, unlike real ones.
     */
    private static function randomProfile(int $seed, int $calls): string
    {
        \mt_srand($seed);
        $content = "version: 1\ncreator: xdebug 3.4.0\ncmd: /app/x.php\npart: 1\npositions: line\n\n"
            . "events: Time_(10ns) Memory_(bytes)\n\n";
        for ($i = 1; $i <= $calls; ++$i) {
            $content .= \sprintf("fl=(1) /app/f%d.php\nfn=(%d) f%d\n2 %d 0\n\n", \mt_rand(), $i, \mt_rand(), \mt_rand(1, 999));
        }
        \mt_srand();

        return $content . "summary: 1 0\n\n";
    }

    private static function info(string $path): FileInfo
    {
        \clearstatcache(true, $path);

        return FileInfo::fromSplFileInfo(new \SplFileInfo($path));
    }

    private static function converter(): XDebug
    {
        return new XDebug(new Logger(), new ProfileBuilder());
    }
}
