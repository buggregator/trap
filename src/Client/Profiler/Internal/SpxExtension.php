<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\Profiler\Internal;

/**
 * The functions of ext-spx the driver calls.
 *
 * @internal
 */
class SpxExtension
{
    /** Replaces the extension, for tests. */
    public static ?self $override = null;

    public static function get(): self
    {
        return self::$override ?? new self();
    }

    public function isLoaded(): bool
    {
        return \function_exists('spx_profiler_start');
    }

    public function config(): SpxConfig
    {
        return SpxConfig::current();
    }

    public function dataDir(): string
    {
        $dir = \ini_get('spx.data_dir');

        return \is_string($dir) && $dir !== '' ? $dir : '/tmp/spx';
    }

    /**
     * SPX tells why it doesn't start only by a notice, with `log_errors` on, which an error handler may turn
     * into an exception.
     */
    public function start(): void
    {
        @spx_profiler_start();
    }

    /**
     * @return string|null The key of the full report; null when SPX made none.
     */
    public function stop(): ?string
    {
        return @spx_profiler_stop();
    }
}
