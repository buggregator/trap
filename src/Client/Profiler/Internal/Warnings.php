<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\Profiler\Internal;

use Buggregator\Trap\Client\TrapHandle;
use Psr\Log\LoggerInterface;

/**
 * Profiler warnings, each kind logged once per process.
 *
 * They go to the Trap logger rather than a dump: with Trap down, a dump falls back to printing
 * into the output or the HTTP response, while the logger falls back to STDERR.
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
        if (isset(self::$sent[$kind])) {
            return;
        }

        self::$sent[$kind] = true;
        try {
            (self::$logger ?? TrapHandle::logger(self::CHANNEL))->warning($message, $context);
        } catch (\Throwable) {
            // A warning must not break the profiled code.
        }
    }
}
