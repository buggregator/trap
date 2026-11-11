<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\Profiler\Internal;

use Buggregator\Trap\Client\TrapHandle;
use Psr\Log\LoggerInterface;

/**
 * Profiler warnings and errors, each kind logged once per process.
 *
 * They go to the Trap logger rather than a dump: with Trap down, a dump falls back to printing
 * into the output or the HTTP response, while the logger falls back to STDERR or `error_log()`.
 *
 * @internal
 */
final class Warnings
{
    public const CHANNEL = 'trap.profiler';

    /** Replaces the Trap logger, for tests. */
    public static ?LoggerInterface $logger = null;

    /** @var array<non-empty-string, true> */
    private static array $sent = [];

    /**
     * @param non-empty-string $kind Logged once per process.
     * @param array<string, mixed> $context
     */
    public static function once(string $kind, string $message, array $context = []): void
    {
        self::log('warning', $kind, $message, $context);
    }

    /**
     * Logs an error of the profiler or a destination, once per process for each message.
     */
    public static function error(string $message, \Throwable $e): void
    {
        self::log('error', 'error:' . $message . "\0" . $e->getMessage(), \sprintf('%s: %s', $message, $e->getMessage()), [
            'exception' => $e::class,
            'file' => $e->getFile() . ':' . $e->getLine(),
        ]);
    }

    /**
     * @param non-empty-string $kind
     * @param array<string, mixed> $context
     */
    private static function log(string $level, string $kind, string $message, array $context): void
    {
        if (isset(self::$sent[$kind])) {
            return;
        }

        self::$sent[$kind] = true;
        try {
            (self::$logger ?? TrapHandle::logger(self::CHANNEL))->log($level, $message, $context);
        } catch (\Throwable) {
            // Logging must not break the profiled code.
        }
    }
}
