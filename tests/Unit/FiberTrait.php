<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit;

trait FiberTrait
{
    /**
     * @template T
     *
     * @param \Closure(): T $callback
     *
     * @return T
     */
    public function runInFiber(\Closure $callback): mixed
    {
        $fiber = new \Fiber($callback);
        $fiber->start();
        do {
            if ($fiber->isTerminated()) {
                return $fiber->getReturn();
            }
            $fiber->resume();
        } while (true);
    }

    /**
     * Runs the fiber until it ends, resuming it at most `$resumes` times: code that waits for data the client
     * never sends fails the test instead of hanging it.
     *
     * @return bool Whether the fiber has ended.
     */
    public function runFiberAtMost(\Fiber $fiber, int $resumes = 10_000): bool
    {
        $fiber->isStarted() or $fiber->start();
        for ($i = 0; $i < $resumes && !$fiber->isTerminated(); ++$i) {
            $fiber->resume();
        }

        return $fiber->isTerminated();
    }

    /**
     * No result, just run.
     */
    public function runInFibers(\Closure ...$callback): void
    {
        $fibers = [];
        foreach ($callback as $closure) {
            $fiber = new \Fiber($closure);
            $fiber->start();
            $fibers[] = $fiber;
        }
        do {
            foreach ($fibers as $key => $fiber) {
                if ($fiber->isTerminated()) {
                    unset($fibers[$key]);
                    continue;
                }
                $fiber->resume();
            }
        } while (\count($fibers) > 0);
    }
}
