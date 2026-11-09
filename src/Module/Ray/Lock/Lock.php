<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Ray\Lock;

/**
 * An execution lock: a paused Ray client waits until the lock is resolved.
 *
 * @internal
 */
final class Lock
{
    /** Null while the client must stay paused. */
    public ?bool $stop = null;

    /** When the lock was resolved, or null. Unix time in seconds. */
    public ?float $resolvedAt = null;

    /**
     * @param float $seenAt When the client was last heard of: lock creation or a poll. Unix time in seconds.
     */
    public function __construct(
        public float $seenAt,
    ) {}

    public function isPending(): bool
    {
        return $this->stop === null;
    }
}
