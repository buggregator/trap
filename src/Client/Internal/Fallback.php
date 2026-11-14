<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\Internal;

/**
 * The last resort for client messages Trap didn't get: STDERR, or `error_log()` without a writable STDERR.
 *
 * @internal
 */
final class Fallback
{
    /**
     * Replaces STDERR, for tests.
     *
     * @var resource|null
     */
    public static mixed $stream = null;

    public static function write(string $line): void
    {
        $stream = self::$stream ?? (\defined('STDERR') ? \STDERR : null);
        try {
            $written = $stream === null ? false : Silently::run(static fn(): int|false => \fwrite($stream, $line . \PHP_EOL));
        } catch (\TypeError) {
            // A closed stream.
            $written = false;
        }

        $written === false || $written === 0 and Silently::run(static fn(): bool => \error_log($line));
    }
}
