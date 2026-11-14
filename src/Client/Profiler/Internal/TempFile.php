<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\Profiler\Internal;

use Buggregator\Trap\Client\Internal\Silently;

/**
 * A new file next to the one it replaces: written aside and moved over it, a write that fails half way
 * leaves the file as it was.
 *
 * @internal
 */
final class TempFile
{
    /** @var (\Closure(string, string): (string|false))|null Replaces `tempnam()`, for tests. */
    public static ?\Closure $tempnam = null;

    /**
     * @return non-empty-string|null Null where the directory takes no new file: `tempnam()` falls back
     *         to the system directory then, from which the file could not be moved in place.
     */
    public static function in(string $dir, string $prefix): ?string
    {
        // `tempnam()` falling back to the system directory notices it: a user error handler that throws on it
        // would fail the write instead of writing in place
        $temp = self::$tempnam instanceof \Closure
            ? (self::$tempnam)($dir, $prefix)
            : Silently::run(static fn(): string|false => \tempnam($dir, $prefix));
        if ($temp === false || $temp === '') {
            return null;
        }
        if (\dirname((string) \realpath($temp)) !== \realpath($dir)) {
            Silently::run(static fn(): bool => \unlink($temp));
            return null;
        }

        return $temp;
    }
}
