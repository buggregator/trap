<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Service\FilesObserver;

use Buggregator\Trap\Client\Profiler\Internal\Model\SpxReport;
use Buggregator\Trap\Module\Monolog\Frame as MonologFrame;
use Buggregator\Trap\Module\Profiler\Config\SPX as SpxConfig;
use Buggregator\Trap\Module\Profiler\Config\XDebug as Config;
use Buggregator\Trap\Module\Profiler\Frame;
use Buggregator\Trap\Server\Config\Files\ObserverConfig;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Server\Service\FilesObserver\Handler;
use Buggregator\Trap\Tests\Unit\Server\Service\FilesObserver\Stub\FakeConfig;
use Buggregator\Trap\Tests\Unit\Server\Service\FilesObserver\Stub\FakeConverter;
use Internal\Container\ObjectContainer;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Covers(Handler::class)]
#[Test]
final class HandlerTest
{
    private const STUB = __DIR__ . '/../../../../Stub/xdebug/shared.cachegrind';
    private const SPX_STUB = __DIR__ . '/../../../../Stub/spx/handwritten';
    private const SPX_KEY = 'spx-full-20250518_120000-app-42-1804289383';
    private const SPX_REAL = __DIR__ . '/../../../../Stub/spx/real/default';

    private string $dir;

    /** @var list<Frame> */
    private array $frames = [];

    /** @var list<string> Messages of the frames of {@see FakeConverter} */
    private array $fakeFrames = [];

    private \Fiber $fiber;

    public function testConvertsCompleteFile(): void
    {
        \file_put_contents($this->dir . '/cachegrind.out.1', (string) \file_get_contents(self::STUB));

        $this->startHandler();

        Assert::same($this->runUntil(fn(): bool => $this->frames !== []), true);
        Assert::same(\count($this->frames[0]->payload->getProfile()->tree()), 15);
    }

    public function testConvertsFileBeingWrittenWhenComplete(): void
    {
        $content = (string) \file_get_contents(self::STUB);
        $path = $this->dir . '/cachegrind.out.1';
        $half = \intdiv(\strlen($content), 2);
        \file_put_contents($path, \substr($content, 0, $half));

        $this->startHandler();
        $this->runUntil(static fn(): bool => false, 0.3);
        Assert::same($this->frames, []);

        \file_put_contents($path, \substr($content, $half), \FILE_APPEND);

        Assert::same($this->runUntil(fn(): bool => $this->frames !== []), true);
        Assert::count($this->frames, 1);
        Assert::same(\count($this->frames[0]->payload->getProfile()->tree()), 15);
    }

    public function testSkipsFilesModifiedBeforeStartWhenConfigured(): void
    {
        $content = (string) \file_get_contents(self::STUB);
        \file_put_contents($this->dir . '/cachegrind.out.old', $content);
        \touch($this->dir . '/cachegrind.out.old', \time() - 60);
        \file_put_contents($this->dir . '/cachegrind.out.new', $content);

        $this->startHandler(skipOldFiles: true);

        Assert::same($this->runUntil(fn(): bool => $this->frames !== []), true);
        $this->runUntil(static fn(): bool => false, 0.2);
        Assert::count($this->frames, 1);
        Assert::same($this->frames[0]->payload->getProfile()->metadata['filename'], 'cachegrind.out.new');
    }

    public function testConvertsOldFilesByDefault(): void
    {
        \file_put_contents($this->dir . '/cachegrind.out.old', (string) \file_get_contents(self::STUB));
        \touch($this->dir . '/cachegrind.out.old', \time() - 60);

        $this->startHandler();

        Assert::same($this->runUntil(fn(): bool => $this->frames !== []), true);
    }

    public function testFollowsOldReportStillBeingWritten(): void
    {
        $data = (string) \gzencode((string) \file_get_contents(self::SPX_STUB . '.txt'));
        $trace = $this->dir . '/' . self::SPX_KEY . '.txt.gz';
        \file_put_contents($trace, \substr($data, 0, \intdiv(\strlen($data), 2)));
        \touch($trace, \time() - 60);

        $this->startHandler(skipOldFiles: true, config: new SpxConfig());
        $this->runUntil(static fn(): bool => false, 0.3);
        Assert::same($this->frames, []);

        // SPX writes the metadata when profiling ends, and closes the trace after it
        \copy(self::SPX_STUB . '.json', $this->dir . '/' . self::SPX_KEY . '.json');
        \file_put_contents($trace, \substr($data, \intdiv(\strlen($data), 2)), \FILE_APPEND);

        // A complete report waits for the client that profiled the run to move it away
        Assert::same($this->runUntil(fn(): bool => $this->frames !== [], 6.0), true);
    }

    public function testSkipsOldReportWrittenCompletely(): void
    {
        $trace = $this->dir . '/' . self::SPX_KEY . '.txt.gz';
        \file_put_contents($trace, \gzencode((string) \file_get_contents(self::SPX_STUB . '.txt')));
        \copy(self::SPX_STUB . '.json', $this->dir . '/' . self::SPX_KEY . '.json');
        \touch($trace, \time() - 60);

        $this->startHandler(skipOldFiles: true, config: new SpxConfig());

        Assert::same($this->runUntil(fn(): bool => $this->frames !== [], 0.3), false);
    }

    public function testFollowsAbandonedFileAgainWhenItChanges(): void
    {
        $content = (string) \file_get_contents(self::STUB);
        $path = $this->dir . '/cachegrind.out.1';
        \file_put_contents($path, \substr($content, 0, \intdiv(\strlen($content), 2)));
        $config = new Config();
        $config->abandonAfter = 0.05;
        $config->scanInterval = 0.1;
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);

        $this->startHandler(config: $config, logger: new Logger($output));
        $this->runUntil(static fn(): bool => false, 1.5);
        Assert::string($output->fetch())->contains('stays incomplete, skipped until it changes');

        \file_put_contents($path, \substr($content, \intdiv(\strlen($content), 2)), \FILE_APPEND);

        Assert::same($this->runUntil(fn(): bool => $this->frames !== []), true);
    }

    public function testFollowsFileUnchangedForLongWithoutAbandonTimeout(): void
    {
        $content = (string) \file_get_contents(self::STUB);
        $path = $this->dir . '/cachegrind.out.1';
        \file_put_contents($path, \substr($content, 0, \intdiv(\strlen($content), 2)));
        $config = new Config();
        $config->abandonAfter = null;

        $this->startHandler(config: $config);
        $this->runUntil(static fn(): bool => false, 1.5);
        \file_put_contents($path, \substr($content, \intdiv(\strlen($content), 2)), \FILE_APPEND);

        Assert::same($this->runUntil(fn(): bool => $this->frames !== []), true);
    }

    public function testWaitsQuietlyForMissingDirectory(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $path = $this->dir . '/spx';
        $config = new Config();
        $config->scanInterval = 0.05;

        $this->startHandler(config: $config, logger: new Logger($output), path: $path);
        $this->runUntil(static fn(): bool => false, 0.3);

        Assert::same(\substr_count($output->fetch(), 'does not exist yet'), 1);

        \mkdir($path);
        \file_put_contents($path . '/cachegrind.out.1', (string) \file_get_contents(self::STUB));
        try {
            Assert::same($this->runUntil(fn(): bool => $this->frames !== []), true);
        } finally {
            \unlink($path . '/cachegrind.out.1');
            \rmdir($path);
        }
    }

    public function testConverterForgetsConvertedFile(): void
    {
        $path = $this->dir . '/a.fake';
        \file_put_contents($path, 'start');

        $this->startHandler(config: new FakeConfig());
        $this->runUntil(static fn(): bool => false, 0.2);
        \file_put_contents($path, 'END', \FILE_APPEND);

        Assert::same($this->runUntil(fn(): bool => $this->fakeFrames !== []), true);
        Assert::same(FakeConverter::$last?->forgotten, [\realpath($path)]);
    }

    public function testConverterForgetsRemovedFile(): void
    {
        $path = $this->dir . '/a.fake';
        \file_put_contents($path, 'start');
        $real = (string) \realpath($path);

        $this->startHandler(config: new FakeConfig());
        $this->runUntil(static fn(): bool => false, 0.2);
        \unlink($path);

        Assert::same($this->runUntil(static fn(): bool => FakeConverter::$last?->forgotten !== []), true);
        Assert::same(FakeConverter::$last?->forgotten, [$real]);
    }

    public function testConverterForgetsAbandonedFileAndItIsLogged(): void
    {
        $path = $this->dir . '/a.fake';
        \file_put_contents($path, 'start');
        $config = new FakeConfig();
        $config->abandonAfter = 0.05;
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);

        $this->startHandler(config: $config, logger: new Logger($output));

        Assert::same($this->runUntil(static fn(): bool => FakeConverter::$last?->forgotten !== []), true);
        Assert::same(FakeConverter::$last?->forgotten, [\realpath($path)]);
        Assert::string($output->fetch())->contains('stays incomplete, skipped until it changes');
    }

    public function testSkipsOldCompleteFileAndFollowsOldIncompleteOne(): void
    {
        \file_put_contents($this->dir . '/complete.fake', 'startEND');
        \file_put_contents($this->dir . '/incomplete.fake', 'start');
        \touch($this->dir . '/complete.fake', \time() - 60);
        \touch($this->dir . '/incomplete.fake', \time() - 60);

        $this->startHandler(skipOldFiles: true, config: new FakeConfig());
        $this->runUntil(static fn(): bool => false, 0.3);
        Assert::same($this->fakeFrames, []);

        \file_put_contents($this->dir . '/incomplete.fake', 'END', \FILE_APPEND);

        Assert::same($this->runUntil(fn(): bool => $this->fakeFrames !== []), true);
        Assert::same($this->fakeFrames, ['incomplete.fake']);
    }

    public function testOldFileUnchangedForLongIsAbandonedUnreadUntilItChanges(): void
    {
        // A trace of a run killed long ago never ends: reading it on each start would be wasted
        $path = $this->dir . '/a.fake';
        \file_put_contents($path, 'start');
        \touch($path, \time() - 7200);
        $config = new FakeConfig();
        $config->abandonAfter = 3600.0;
        $config->scanInterval = 0.1;

        $this->startHandler(skipOldFiles: true, config: $config);
        $this->runUntil(static fn(): bool => false, 0.3);
        Assert::same(FakeConverter::$last?->checked, []);

        \file_put_contents($path, 'END', \FILE_APPEND);

        Assert::same($this->runUntil(fn(): bool => $this->fakeFrames !== []), true);
        Assert::same($this->fakeFrames, ['a.fake']);
    }

    public function testSkipsUnreadableSubdirectoryAndKeepsItsFiles(): void
    {
        \mkdir($this->dir . '/private');
        \file_put_contents($this->dir . '/private/b.fake', 'startEND');
        \file_put_contents($this->dir . '/a.fake', 'startEND');
        $locked = false;
        // A private directory of another user, as in `/tmp`
        $listing = static function (string $dir) use (&$locked): \FilesystemIterator {
            $locked && \str_ends_with($dir, 'private') and throw new \UnexpectedValueException("Permission denied: $dir");
            return new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS);
        };
        $config = new FakeConfig();
        $config->scanInterval = 0.1;
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $this->startHandler(config: $config, logger: new Logger($output), listing: $listing);
        Assert::same($this->runUntil(fn(): bool => \count($this->fakeFrames) === 2), true);

        $locked = true;
        \file_put_contents($this->dir . '/c.fake', 'startEND');
        Assert::same($this->runUntil(fn(): bool => \count($this->fakeFrames) === 3), true);
        $this->runUntil(static fn(): bool => false, 0.5);
        $locked = false;
        $this->runUntil(static fn(): bool => false, 0.5);
        \unlink($this->dir . '/private/b.fake');
        \rmdir($this->dir . '/private');

        // The files of the root are still taken, and the ones of the subdirectory are not taken again
        Assert::same(\count($this->fakeFrames), 3);
        Assert::same(\substr_count($output->fetch(), 'can not be read, skipped'), 1);
    }

    public function testTakesFilePutBackSoonAfterItWasTakenAway(): void
    {
        // A client puts back a report it could not deliver, within a scan interval
        \file_put_contents($this->dir . '/a.fake', 'start');
        $config = new FakeConfig();
        $config->scanInterval = 0.5;
        $this->startHandler(config: $config);
        $this->runUntil(static fn(): bool => false, 0.2);

        \rename($this->dir . '/a.fake', $this->dir . '/aside');
        $this->runUntil(static fn(): bool => false, 1.2);
        \file_put_contents($this->dir . '/aside', 'END', \FILE_APPEND);
        \rename($this->dir . '/aside', $this->dir . '/a.fake');

        Assert::same($this->runUntil(fn(): bool => $this->fakeFrames !== []), true);
    }

    public function testTakesFilesOfDirectoryRecreatedAfterItWasMissing(): void
    {
        $path = $this->dir . '/spx';
        \mkdir($path);
        \file_put_contents($path . '/a.fake', 'startEND');
        $config = new FakeConfig();
        $config->scanInterval = 0.1;
        $this->startHandler(config: $config, path: $path);
        Assert::same($this->runUntil(fn(): bool => $this->fakeFrames !== []), true);

        \unlink($path . '/a.fake');
        \rmdir($path);
        $this->runUntil(static fn(): bool => false, 0.3);
        \mkdir($path);
        \file_put_contents($path . '/a.fake', 'a new run, END');

        try {
            Assert::same($this->runUntil(fn(): bool => \count($this->fakeFrames) === 2), true);
        } finally {
            \unlink($path . '/a.fake');
            \rmdir($path);
        }
    }

    public function testSkipsReportTheClientTakesAwayDuringTheGracePeriod(): void
    {
        $config = new SpxConfig();
        $config->scanInterval = 0.1;
        $this->startHandler(config: $config);

        // SPX finishes a report of a run profiled from code, and the client takes it at once
        \file_put_contents($this->dir . '/' . self::SPX_KEY . '.txt.gz', \gzencode((string) \file_get_contents(self::SPX_STUB . '.txt')));
        \copy(self::SPX_STUB . '.json', $this->dir . '/' . self::SPX_KEY . '.json');
        $this->runUntil(static fn(): bool => false, 1.2);
        $report = SpxReport::read($this->dir, self::SPX_KEY, keep: false);

        Assert::same($this->runUntil(fn(): bool => $this->frames !== [], 3.0), false);
        $report->settle(true);
        unset($report);
    }

    public function testTakesReportPutBackUnchangedAfterItWasAway(): void
    {
        $config = new SpxConfig();
        $config->scanInterval = 0.1;
        $this->startHandler(config: $config);
        $trace = $this->dir . '/' . self::SPX_KEY . '.txt.gz';
        \copy(self::SPX_REAL . '.txt.gz', $trace);
        \copy(self::SPX_REAL . '.json', $this->dir . '/' . self::SPX_KEY . '.json');
        \clearstatcache(true, $trace);
        $mtime = (int) \filemtime($trace);
        $this->runUntil(static fn(): bool => false, 0.5);

        // Taken aside by a client during the grace period, and put back undelivered with the time it had
        \rename($trace, $this->dir . '/aside');
        $this->runUntil(static fn(): bool => false, 1.2);
        \rename($this->dir . '/aside', $trace);
        \touch($trace, $mtime);

        Assert::same($this->runUntil(fn(): bool => $this->frames !== [], 5.0), true);
        Assert::same($this->frames[0]->payload->getProfile()->metadata['details']['Command'], 'calls.php');
    }

    public function testConvertsNothingAgainAfterListingFails(): void
    {
        \file_put_contents($this->dir . '/a.fake', 'startEND');
        \file_put_contents($this->dir . '/b.fake', 'startEND');
        $config = new FakeConfig();
        $config->scanInterval = 0.1;
        $this->startHandler(config: $config);
        Assert::same($this->runUntil(fn(): bool => \count($this->fakeFrames) === 2), true);

        FakeConverter::$failListing = true;
        try {
            $this->runUntil(static fn(): bool => false, 0.3);
        } finally {
            FakeConverter::$failListing = false;
        }
        $this->runUntil(static fn(): bool => false, 0.3);

        Assert::count($this->fakeFrames, 2);
    }

    public function testConvertsNothingAgainAfterDirectoryIsMissingForAWhile(): void
    {
        $path = $this->dir . '/spx';
        \mkdir($path);
        \file_put_contents($path . '/a.fake', 'startEND');
        $config = new FakeConfig();
        $config->scanInterval = 0.1;
        $this->startHandler(config: $config, path: $path);
        Assert::same($this->runUntil(fn(): bool => $this->fakeFrames !== []), true);

        \rename($path, $this->dir . '/away');
        $this->runUntil(static fn(): bool => false, 0.3);
        \rename($this->dir . '/away', $path);
        $this->runUntil(static fn(): bool => false, 0.3);
        \unlink($path . '/a.fake');
        \rmdir($path);

        Assert::same($this->fakeFrames, ['a.fake']);
    }

    #[BeforeTest]
    public function createDir(): void
    {
        FakeConverter::$failListing = false;
        $this->frames = [];
        $this->fakeFrames = [];
        FakeConverter::$last = null;
        $this->dir = \sys_get_temp_dir() . '/' . \uniqid('trap-observer-', true);
        \mkdir($this->dir);
    }

    #[AfterTest]
    public function removeDir(): void
    {
        \array_map(\unlink(...), (array) \glob($this->dir . '/*'));
        \rmdir($this->dir);
    }

    private function startHandler(
        bool $skipOldFiles = false,
        ObserverConfig $config = new Config(),
        ?Logger $logger = null,
        ?string $path = null,
        ?\Closure $listing = null,
    ): void {
        $config->path = $path ?? $this->dir;
        $config->skipOldFiles = $skipOldFiles;
        // Longer than the test runs: only the first scan finds files, the rest is up to following them
        $config->scanInterval === 5.0 and $config->scanInterval = 60.0;
        $handler = new Handler($config, $logger ?? new Logger(), new ObjectContainer(), $listing);

        $this->fiber = new \Fiber(function () use ($handler): void {
            foreach ($handler as $frame) {
                $frame instanceof Frame and $this->frames[] = $frame;
                $frame instanceof MonologFrame and $this->fakeFrames[] = (string) $frame->message['message'];
            }
        });
        $this->fiber->start();
    }

    /**
     * @param \Closure(): bool $condition
     */
    private function runUntil(\Closure $condition, float $timeout = 3.0): bool
    {
        $deadline = \microtime(true) + $timeout;
        while (\microtime(true) < $deadline) {
            if ($condition()) {
                return true;
            }
            $this->fiber->resume();
            \usleep(1000);
        }

        return $condition();
    }
}
