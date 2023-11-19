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
                try {
                    if ($fiber->isTerminated()) {
                        unset($fibers[$key]);
                        continue;
                    }
                    $fiber->resume();
                } catch (\Throwable) {
                    unset($fibers[$key]);
                }
            }
        } while (\count($fibers) > 0);
    }
}
