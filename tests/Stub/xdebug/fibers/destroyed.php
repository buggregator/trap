<?php

declare(strict_types=1);

function work(): string
{
    return \str_repeat('x', 10);
}

function task(): void
{
    work();
    \Fiber::suspend();
    work();
}

function run(): void
{
    $fiber = new \Fiber('task');
    $fiber->start();
    work();
    unset($fiber);
    work();
}

run();
