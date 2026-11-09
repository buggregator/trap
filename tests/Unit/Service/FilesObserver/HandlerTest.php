<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Service\FilesObserver;

use Buggregator\Trap\Config\Server\Files\XDebug as Config;
use Buggregator\Trap\Logger;
use Buggregator\Trap\Proto\Frame\Profiler;
use Buggregator\Trap\Service\FilesObserver\Handler;
use Internal\Container\ObjectContainer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Covers(Handler::class)]
#[Test]
final class HandlerTest
{
    private const STUB = __DIR__ . '/../../../Stub/xdebug/shared.cachegrind';

    private string $dir;

    /** @var list<Profiler> */
    private array $frames = [];

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

    #[BeforeTest]
    public function createDir(): void
    {
        $this->frames = [];
        $this->dir = \sys_get_temp_dir() . '/' . \uniqid('trap-observer-', true);
        \mkdir($this->dir);
    }

    #[AfterTest]
    public function removeDir(): void
    {
        \array_map(\unlink(...), (array) \glob($this->dir . '/*'));
        \rmdir($this->dir);
    }

    private function startHandler(): void
    {
        $config = new Config();
        $config->path = $this->dir;
        // Longer than the test runs: only the first scan finds files, the rest is up to following them
        $config->scanInterval = 60.0;
        $handler = new Handler($config, new Logger(), new ObjectContainer());

        $this->fiber = new \Fiber(function () use ($handler): void {
            foreach ($handler as $frame) {
                $frame instanceof Profiler and $this->frames[] = $frame;
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
