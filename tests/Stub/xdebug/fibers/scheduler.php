<?php

declare(strict_types=1);

function work(): string
{
    return \str_repeat('x', 10);
}

function task(int $steps): void
{
    for ($i = 0; $i < $steps; ++$i) {
        work();
        \Fiber::suspend();
    }
    work();
}

function run(): void
{
    $fibers = [];
    for ($i = 0; $i < 50; ++$i) {
        $fiber = new \Fiber('task');
        $fiber->start(3 + $i % 4);
        $fibers[] = $fiber;
    }
    while ($fibers !== []) {
        foreach ($fibers as $key => $fiber) {
            $fiber->resume();
            if ($fiber->isTerminated()) {
                unset($fibers[$key]);
            }
        }
    }
}

run();
