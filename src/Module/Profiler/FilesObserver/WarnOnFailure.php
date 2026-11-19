<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\FilesObserver;

use Buggregator\Trap\Module\Profiler\Struct\Profile;
use Buggregator\Trap\Server\Logger;

/**
 * Warns about a profile file that fails to build, at the default verbosity and once per file:
 * a failed profile is not kept, so a consumer that asks for it again would fail on it again.
 *
 * @internal
 */
final class WarnOnFailure
{
    /**
     * @param non-empty-string $source Name of the profiler, the prefix of the warning.
     * @param \Closure(): Profile $build
     *
     * @return \Closure(): Profile Rethrows what the build throws.
     */
    public static function wrap(Logger $logger, string $source, string $name, \Closure $build): \Closure
    {
        $warned = false;

        return static function () use ($logger, $source, $name, $build, &$warned): Profile {
            try {
                return $build();
            } catch (\Throwable $e) {
                $warned or $logger->warning('%s: %s is skipped: %s', $source, $name, $e->getMessage());
                $warned = true;
                throw $e;
            }
        };
    }
}
