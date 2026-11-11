<?php

declare(strict_types=1);

namespace Buggregator\Trap\Client\Profiler\Internal;

use Buggregator\Trap\Client\Profiler\Format;

/**
 * A profiler backend: a value object carrying the profiler options.
 *
 * @internal Implemented by the bundled drivers only; the contract may change in any release.
 */
interface Driver
{
    /**
     * @return non-empty-string
     */
    public function name(): string;

    /**
     * Whether the extension the driver needs is loaded.
     */
    public function isAvailable(): bool;

    /**
     * Formats the recorded profile can be encoded in, the native one first.
     *
     * @return non-empty-list<Format>
     */
    public function formats(): array;

    /**
     * Options to report alongside the profile.
     *
     * @return array<non-empty-string, scalar|list<string>|null>
     */
    public function options(): array;

    /**
     * Starts profiling. Called only when {@see isAvailable()} is true and no other profile is running.
     */
    public function start(): Recording;
}
