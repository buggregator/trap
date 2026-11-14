<?php

declare(strict_types=1);

function before(): void {}

function inside(): int
{
    return \array_sum(\range(1, 10));
}

function after(): void {}

before();
spx_profiler_start();
inside();
inside();
spx_profiler_stop();
after();
