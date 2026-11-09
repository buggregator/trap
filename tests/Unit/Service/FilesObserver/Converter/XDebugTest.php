<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Service\FilesObserver\Converter;

use Buggregator\Trap\Logger;
use Buggregator\Trap\Module\Profiler\XDebug\ProfileBuilder;
use Buggregator\Trap\Proto\Frame\Profiler;
use Buggregator\Trap\Service\FilesObserver\Converter\XDebug;
use Buggregator\Trap\Service\FilesObserver\FileInfo;
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
        Assert::instanceOf($frames[0], Profiler::class);
        Assert::same($frames[0]->payload->type, Profiler\Type::XDebug);
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
