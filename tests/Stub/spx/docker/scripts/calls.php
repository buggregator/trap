<?php

declare(strict_types=1);

require __DIR__ . '/lib.php';

final class Greeter
{
    public function greet(string $name): string
    {
        return \strtoupper("hi $name");
    }
}

function fib(int $n): int
{
    return $n < 2 ? $n : fib($n - 1) + fib($n - 2);
}

function run(): int
{
    $double = static fn(int $x): int => $x * 2;

    return $double(fib(5)) + \strlen((new \Greeter())->greet(lib()));
}

echo run(), "\n";
