<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Service;

use Buggregator\Trap\Module\Profiler\Config\XDebug as Config;
use Buggregator\Trap\Module\Profiler\Frame;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Server\Proto\Buffer;
use Buggregator\Trap\Server\Service\FilesObserver;
use Internal\Container\ObjectContainer;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Covers(FilesObserver::class)]
#[Test]
final class FilesObserverTest
{
    private const STUB = __DIR__ . '/../../../Stub/xdebug/shared.cachegrind';

    /** Ready to read, as it ends with the summary line, but not a profile */
    private const BROKEN = "not a profile\n\nsummary: 1 0\n\n";

    private string $dir;
    private BufferedOutput $output;
    private Buffer $buffer;
    private FilesObserver $observer;

    public function testFileFailingToImportIsReportedAndLaterFilesAreImported(): void
    {
        \file_put_contents($this->dir . '/cachegrind.out.1', self::BROKEN);
        $this->startObserver();
        $this->runFor(0.5);
        Assert::same($this->buffer->getAndClean(), []);

        \file_put_contents($this->dir . '/cachegrind.out.2', (string) \file_get_contents(self::STUB));

        Assert::true($this->runUntil(fn(): bool => $this->buffer->getSize() > 0));
        $frames = $this->buffer->getAndClean();
        Assert::count($frames, 1);
        Assert::instanceOf($frames[0], Frame::class);
        Assert::same($frames[0]->payload->getProfile()->metadata['filename'], 'cachegrind.out.2');
        $log = $this->output->fetch();
        Assert::same(\substr_count($log, 'cachegrind.out.1'), 1);
        Assert::string($log)->contains('XDebug: cachegrind.out.1 is skipped: Not a cachegrind profile written by Xdebug.');
    }

    public function testImportsGzippedProfiles(): void
    {
        // Xdebug compresses its files with `xdebug.use_compression`, which is on by default
        $this->startObserver();
        $this->runFor(0.2);

        \file_put_contents($this->dir . '/cachegrind.out.1.gz', \gzencode((string) \file_get_contents(self::STUB)));

        Assert::true($this->runUntil(fn(): bool => $this->buffer->getSize() > 0));
        $frames = $this->buffer->getAndClean();
        Assert::count($frames, 1);
        Assert::same($frames[0]->payload->getProfile()->metadata['filename'], 'cachegrind.out.1.gz');
    }

    #[BeforeTest]
    public function createDir(): void
    {
        $this->dir = \sys_get_temp_dir() . '/' . \uniqid('trap-files-observer-', true);
        \mkdir($this->dir);
    }

    #[AfterTest]
    public function removeDir(): void
    {
        \array_map(\unlink(...), (array) \glob($this->dir . '/*'));
        \rmdir($this->dir);
    }

    private function startObserver(): void
    {
        $config = new Config();
        $config->path = $this->dir;
        $config->scanInterval = 0.1;
        $this->output = new BufferedOutput();
        $logger = new Logger($this->output);
        $container = new ObjectContainer();
        $container->set($logger);
        $this->buffer = new Buffer(bufferSize: \PHP_INT_MAX);
        $this->observer = new FilesObserver($container, $logger, $this->buffer, $config);
    }

    private function runFor(float $seconds): void
    {
        $this->runUntil(static fn(): bool => false, $seconds);
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
            $this->observer->process();
            \usleep(1000);
        }

        return $condition();
    }
}
