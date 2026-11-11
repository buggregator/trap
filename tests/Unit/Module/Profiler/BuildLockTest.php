<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler;

use Buggregator\Trap\Module\Profiler\BuildLock;
use Buggregator\Trap\Module\Profiler\BuildQueueFull;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Covers(BuildLock::class)]
#[Test]
final class BuildLockTest
{
    /** @var list<string> */
    private array $log = [];

    #[BeforeTest]
    public function resetLog(): void
    {
        $this->log = [];
    }

    public function testBuildsInFibersRunOneAfterAnother(): void
    {
        $lock = new BuildLock();
        $fibers = [$this->build($lock, 'a', steps: 3), $this->build($lock, 'b', steps: 2), $this->build($lock, 'c', steps: 1)];

        self::runAll($fibers);

        Assert::same($this->log, ['a0', 'a1', 'a2', 'b0', 'b1', 'c0']);
    }

    public function testFailingBuildReleasesLock(): void
    {
        $lock = new BuildLock();
        $failing = new \Fiber(static fn() => $lock->run(static function (): never {
            \Fiber::suspend();
            throw new \RuntimeException('broken profile');
        }));
        $next = $this->build($lock, 'next', steps: 1);

        $failing->start();
        $next->start();
        Assert::same($this->log, []);
        Expect::exception(\RuntimeException::class)->withMessage('broken profile');
        try {
            $failing->resume();
        } finally {
            $next->resume();
            Assert::same($this->log, ['next0']);
        }
    }

    public function testRefusesBuildsOverWaitingLimit(): void
    {
        $lock = new BuildLock(maxWaiting: 1);
        $first = $this->build($lock, 'first', steps: 1);
        $second = $this->build($lock, 'second', steps: 1);
        $third = new \Fiber(static fn() => $lock->run(static fn() => null));
        $first->start();
        $second->start();

        Expect::exception(BuildQueueFull::class)->withMessageContaining('1 profile builds are waiting');

        $third->start();
    }

    public function testBuildOutsideFiberRunsAtOnce(): void
    {
        $lock = new BuildLock();
        $holder = $this->build($lock, 'fiber', steps: 2);
        $holder->start();

        $result = $lock->run(static fn(): string => 'built');

        Assert::same($result, 'built');
        self::runAll([$holder]);
        Assert::same($this->log, ['fiber0', 'fiber1']);
    }

    public function testDestroyedWaitingFiberLeavesLine(): void
    {
        $lock = new BuildLock(maxWaiting: 1);
        $holder = $this->build($lock, 'holder', steps: 1);
        $waiting = $this->build($lock, 'gone', steps: 1);
        $holder->start();
        $waiting->start();

        unset($waiting);
        $next = $this->build($lock, 'next', steps: 1);
        self::runAll([$holder, $next]);

        Assert::same($this->log, ['holder0', 'next0']);
    }

    /**
     * Resumes the fibers in turns, as the server loop does, until they all end.
     *
     * @param list<\Fiber> $fibers
     */
    private static function runAll(array $fibers): void
    {
        do {
            $running = false;
            foreach ($fibers as $fiber) {
                if ($fiber->isTerminated()) {
                    continue;
                }
                $fiber->isStarted() ? $fiber->resume() : $fiber->start();
                $running = true;
            }
        } while ($running);
    }

    /**
     * A build that logs a step, then lets the other fibers run, `$steps` times.
     */
    private function build(BuildLock $lock, string $name, int $steps): \Fiber
    {
        return new \Fiber(function () use ($lock, $name, $steps): void {
            $lock->run(function () use ($name, $steps): void {
                for ($i = 0; $i < $steps; ++$i) {
                    $this->log[] = $name . $i;
                    \Fiber::suspend();
                }
            });
        });
    }
}
