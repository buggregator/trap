<?php

declare(strict_types=1);

namespace Buggregator\Client;

/**
 * Console color logger
 */
class Logger
{
    public static function info(string $message, string|int|float|bool ...$values): void
    {
        echo "\033[32m" . \sprintf($message, ...$values) . "\033[0m\n";
    }

    public static function error(string $message, string|int|float|bool ...$values): void
    {
        echo "\033[31m" . \sprintf($message, ...$values) . "\033[0m\n";
    }
}
