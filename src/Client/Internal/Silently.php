<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\Internal;

/**
 * Runs an I/O call whose warnings the caller handles out of reach of user error handlers:
 * a handler that throws on every warning ignores `@` and would skip that handling.
 *
 * @internal
 */
final class Silently
{
    /**
     * The callable must never suspend a fiber: the no-op handler would stay installed for the code that resumes.
     *
     * @template T
     *
     * @param \Closure(): T $io
     *
     * @return T
     */
    public static function run(\Closure $io): mixed
    {
        \set_error_handler(static fn(): bool => true);
        try {
            return $io();
        } finally {
            \restore_error_handler();
        }
    }
}
