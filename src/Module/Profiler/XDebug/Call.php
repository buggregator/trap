<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler\XDebug;

/**
 * A call read from a cachegrind block, until it is linked to the tree.
 *
 * @internal
 */
final class Call
{
    /** @var int Own time in the units of the profile. */
    public int $time = 0;

    /** @var int Own memory in bytes. */
    public int $memory = 0;

    /** @var list<non-empty-string> Names of the called functions, in the call order. */
    public array $callees = [];

    /**
     * @param non-empty-string $name
     */
    public function __construct(
        public readonly string $name,
    ) {}
}
