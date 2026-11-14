<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\Profiler\Internal;

/**
 * The SPX settings of the process, read as SPX reads them when it starts.
 *
 * A CLI script takes them from the environment, which tells whether `spx_profiler_start()` can work: SPX must be
 * enabled, its automatic start off, and its report `full`. A web request takes them from the `SPX_*` cookies,
 * headers and query parameters, the later ones winning, when SPX grants the client the access, and otherwise from
 * the `spx.http_profiling_*` INI settings; its report is always full. The access is not checked here: a request
 * SPX doesn't profile just gets no report.
 *
 * @link https://github.com/NoiseByNorthwest/php-spx#available-parameters
 *
 * @internal
 */
final class SpxConfig
{
    /**
     * @param \Closure(non-empty-string): ?string $setting The value of an `SPX_*` parameter, null when unset.
     */
    public function __construct(
        private readonly bool $cli,
        private readonly \Closure $setting,
    ) {}

    public static function current(): self
    {
        if (\PHP_SAPI === 'cli') {
            return new self(true, static function (string $name): ?string {
                // `?:` would take `0` for no value
                $value = \getenv($name, true);
                $value === false and $value = \getenv($name);
                return $value === false ? null : $value;
            });
        }

        return new self(false, static function (string $name): ?string {
            /** @var mixed $value */
            $value = $_GET[$name] ?? $_SERVER['HTTP_' . $name] ?? $_COOKIE[$name]
                ?? \ini_get('spx.http_profiling_' . \strtolower(\substr($name, 4)));
            return \is_string($value) && $value !== '' ? $value : null;
        });
    }

    /**
     * @return non-empty-string|null Why `spx_profiler_start()` won't work in a CLI script and how to fix it;
     *         null when it will, or for a web request, which SPX checks itself.
     */
    public function problem(): ?string
    {
        if (!$this->cli) {
            return null;
        }

        return match (true) {
            ($this->setting('SPX_ENABLED')[0] ?? '') !== '1'
                => 'SPX is not enabled: set `SPX_ENABLED=1` in the environment of the process.',
            ($this->setting('SPX_AUTO_START')[0] ?? '1') === '1'
                => 'SPX profiles the whole run by itself: set `SPX_AUTO_START=0` in the environment of the process.',
            $this->setting('SPX_REPORT') !== 'full'
                => 'SPX writes no full report: set `SPX_REPORT=full` in the environment of the process.',
            default => null,
        };
    }

    /**
     * @return positive-int|null Microseconds between the samples when SPX samples the stack.
     */
    public function samplingPeriod(): ?int
    {
        $period = (int) $this->setting('SPX_SAMPLING_PERIOD');

        return $period > 0 ? $period : null;
    }

    /**
     * @param non-empty-string $name
     */
    private function setting(string $name): ?string
    {
        return ($this->setting)($name);
    }
}
