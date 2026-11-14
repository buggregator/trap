<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server\Config;

use Buggregator\Trap\Server\Application\Config\Internal\Attribute\Env;
use Buggregator\Trap\Server\Application\Config\Internal\Attribute\InflectableConfig;

/**
 * Common configuration for the application
 *
 * @internal
 * @psalm-internal Buggregator\Trap
 */
#[InflectableConfig]
final class App
{
    /**
     * Longest wait of an idle main loop in microseconds: data on a socket ends the wait sooner.
     * Timers of the loop, as a buffer flush or a reconnect, fire this much late at most.
     * On Windows any wait lasts at least ~15 ms.
     * @var int<50, max>
     */
    #[Env('TRAP_MAIN_LOOP_INTERVAL')]
    public int $mainLoopInterval = 1_000;

    /**
     * Seconds between collections of garbage cycles, run by the main loop instead of PHP's cycle collector;
     * `0` leaves PHP's collector on. PHP's collector would walk a profile's call tree, linked both ways,
     * on each of its runs while the profile is built.
     * @var int<0, max>
     */
    #[Env('TRAP_GC_INTERVAL')]
    public int $gcInterval = 30;

    /**
     * Memory limit the server raises PHP's `memory_limit` to when it is lower, as `512M`, `1G` or `-1`;
     * an empty value leaves it as it is. Profiles get as many call tree nodes as the memory left affords:
     * PHP's default 128M gives a large profile about 25 thousand nodes, 512M about 190 thousand.
     */
    #[Env('TRAP_MEMORY_LIMIT')]
    public string $memoryLimit = '512M';

    /**
     * Size in bytes a gzip-encoded HTTP request body may inflate to, as `67108864`, `512K`, `64M` or `1G`
     * (binary multiples, any case); a larger body is answered with 413.
     * Any other value, or a size below 1 byte, falls back to the default with a warning.
     * A string, not an int: the config inflector would cast `64M` to 64.
     */
    #[Env('TRAP_HTTP_MAX_INFLATED_SIZE')]
    public string $httpMaxInflatedSize = '64M';
}
