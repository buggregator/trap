<?php

declare(strict_types=1);

function tick(): void {}

function spin(float $seconds): void
{
    $until = \microtime(true) + $seconds;
    while (\microtime(true) < $until) {
        tick();
    }
}

function outer(): void
{
    spin(0.002);
    inner();
}

function inner(): void
{
    spin(0.002);
}

outer();
