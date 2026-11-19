<?php

declare(strict_types=1);

function work(): string
{
    return \str_repeat('x', 10);
}

function task(): void
{
    while (true) {
        work();
        \Fiber::suspend();
    }
}

function run(): void
{
    $fiber = new \Fiber('task');
    $fiber->start();
    for ($i = 0; $i < 5; ++$i) {
        work();
        $fiber->resume();
    }
}

run();
