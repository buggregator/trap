<?php

declare(strict_types=1);

function work(): string
{
    return \str_repeat('x', 10);
}

function inner(): void
{
    work();
    \Fiber::suspend();
    work();
}

function outer(): void
{
    $fiber = new \Fiber('inner');
    $fiber->start();
    work();
    \Fiber::suspend();
    $fiber->resume();
    work();
}

function run(): void
{
    $fiber = new \Fiber('outer');
    $fiber->start();
    work();
    $fiber->resume();
    work();
}

run();
