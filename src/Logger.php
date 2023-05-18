<?php

declare(strict_types=1);

namespace Buggregator\Client;

use Throwable;

/**
 * Console color logger
 */
final class Logger
{
    public static function print(string $message, string|int|float|bool ...$values): void
    {
        echo \sprintf($message, ...$values) . "\n";
    }

    public static function info(string $message, string|int|float|bool ...$values): void
    {
        echo "\033[32m" . \sprintf($message, ...$values) . "\033[0m\n";
    }

    public static function dump(mixed ...$values): void
    {
        echo "\033[33m";
        foreach ($values as $value) {
            \var_dump($value);
        }
        echo "\033[0m\n";
    }

    public static function debug(string $message, string|int|float|bool ...$values): void
    {
        echo "\033[34m" . \sprintf($message, ...$values) . "\033[0m\n";
    }

    public static function error(string $message, string|int|float|bool ...$values): void
    {
        echo "\033[31m" . \sprintf($message, ...$values) . "\033[0m\n";
    }

    public static function exception(Throwable $e, ?string $header = null): void
    {
        echo "----------------------\n";
        // Print bold yellow header if exists
        if ($header !== null) {
            echo "\033[1;33m" . $header . "\033[0m\n";
        }
        // Print exception message
        echo $e->getMessage() . "\n";
        // Print file and line using green color and italic font
        echo "In \033[3;32m" . $e->getFile() . ':' . $e->getLine() . "\033[0m\n";
        // Print stack trace using gray
        echo "Stack trace:\n";
        echo "\033[90m" . $e->getTraceAsString() . "\033[0m\n";
        echo "\n";
    }
}
