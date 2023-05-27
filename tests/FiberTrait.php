<?php

declare(strict_types=1);

namespace Buggregator\Client\Tests;

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
}
