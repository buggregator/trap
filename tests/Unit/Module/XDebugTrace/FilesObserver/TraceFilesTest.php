<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\XDebugTrace\FilesObserver;

use Buggregator\Trap\Module\XDebugTrace\Config\TraceDirectory;
use Buggregator\Trap\Module\XDebugTrace\Config\Traces;
use Buggregator\Trap\Module\XDebugTrace\FilesObserver\TraceFiles;
use Buggregator\Trap\Module\XDebugTrace\Frame;
use Buggregator\Trap\Module\XDebugTrace\TraceStorage;
use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Server\Service\FilesObserver\FileInfo;
use Buggregator\Trap\Tests\Unit\Module\XDebugTrace\Trace\TraceReaderTest;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

#[Covers(TraceFiles::class)]
#[Covers(TraceDirectory::class)]
#[Test]
final class TraceFilesTest
{
    /** @var list<string> */
    private array $files = [];

    #[AfterTest]
    public function removeFiles(): void
    {
        foreach ($this->files as $file) {
            @\unlink($file);
        }
    }

    public function testRecognizesTracesByExtension(): void
    {
        $converter = self::converter();

        Assert::true($converter->validate(new FileInfo('/tmp/trace.123.xt', 1, 0, 0)));
        Assert::true($converter->validate(new FileInfo('/tmp/trace.123.xt.gz', 1, 0, 0)));
        Assert::false($converter->validate(new FileInfo('/tmp/cachegrind.out.1', 1, 0, 0)));
        // The copies of the storage, as when the watched directory is the temp one
        Assert::false($converter->validate(new FileInfo('/tmp/trap-xdebug-traces-12-ab/0190402f.xt.gz', 1, 0, 0)));
        Assert::false($converter->validate(new FileInfo('C:\Temp\trap-xdebug-traces-12-ab\0190402f.xt', 1, 0, 0)));
        Assert::true($converter->validate(new FileInfo('/tmp/trap-xdebug-traces.xt', 1, 0, 0)));
    }

    public function testWaitsForTraceEnd(): void
    {
        $plain = (string) \gzdecode((string) \file_get_contents(TraceReaderTest::MAIN));
        $converter = self::converter();

        Assert::true($converter->isReady($this->info($plain, '.xt')));
        Assert::false($converter->isReady($this->info(\substr($plain, 0, 2000), '.xt')));
        Assert::true($converter->isReady($this->info(\gzencode($plain), '.xt.gz')));
        Assert::false($converter->isReady($this->info(\substr((string) \gzencode($plain), 0, 300), '.xt.gz')));
    }

    public function testConvertsTraceIntoFrame(): void
    {
        $info = $this->info((string) \file_get_contents(TraceReaderTest::MAIN), '.xt.gz');

        $frames = \iterator_to_array(self::converter()->convert($info));

        Assert::count($frames, 1);
        Assert::instanceOf($frames[0], Frame::class);
        Assert::same($frames[0]->name, \basename($info->path));
        Assert::same($frames[0]->summary['calls'], 39);
    }

    public function testSkipsFileOverLimit(): void
    {
        $config = new Traces();
        $config->maxUpload = '1K';
        $output = new BufferedOutput();
        $info = $this->info((string) \file_get_contents(TraceReaderTest::MAIN), '.xt.gz');
        $converter = self::converter($config, $output);

        $ready = $converter->isReady($info);
        $frames = \iterator_to_array($converter->convert($info));

        Assert::true($ready);
        Assert::same($frames, []);
        Assert::string($output->fetch())->contains('is skipped: it is larger than 1024 bytes');
    }

    public function testWatchesOutputDirWhileTracing(): void
    {
        $config = new TraceDirectory();
        $config->mode = 'debug, trace';
        $config->outputDir = '/tmp/xdebug';

        Assert::true($config->isValid());
        Assert::same($config->path, '/tmp/xdebug');
    }

    public function testDoesNotWatchOutputDirWithoutTracing(): void
    {
        $config = new TraceDirectory();
        $config->mode = 'profile';
        $config->outputDir = '/tmp/xdebug';

        Assert::false($config->isValid());
    }

    private static function converter(Traces $config = new Traces(), ?BufferedOutput $output = null): TraceFiles
    {
        return new TraceFiles(
            new Logger($output ?? new BufferedOutput()),
            new TraceStorage($config, new EventStore()),
            $config,
        );
    }

    private function info(string $content, string $extension): FileInfo
    {
        $path = \sys_get_temp_dir() . '/trap-xt-test-' . \bin2hex(\random_bytes(4)) . $extension;
        \file_put_contents($path, $content);
        $this->files[] = $path;

        return new FileInfo($path, \strlen($content), \time(), \time());
    }
}
