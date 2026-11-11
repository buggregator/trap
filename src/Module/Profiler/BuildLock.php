<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Profiler;

/**
 * Lets one profile build run at a time: each takes most of the memory left, see {@see Compaction\NodeBudget}.
 *
 * The builds run in fibers and let the other fibers run meanwhile, so a build that finds the lock taken
 * suspends its fiber until its turn comes, first come first served. A waiting build holds its request body,
 * so the waiting line is short: past it, {@see self::run()} refuses.
 *
 * Outside a fiber nothing waits: such a build runs at once, as nothing can run along it but
 * the fibers it doesn't resume.
 *
 * @internal
 */
final class BuildLock
{
    /** Builds that may wait for the lock. */
    public const MAX_WAITING = 4;

    private bool $taken = false;

    /** Object ID of the fiber that took the lock; null when not taken, or taken outside a fiber */
    private ?int $holder = null;

    /** @var list<int> Tickets of the waiting builds, in the order they came */
    private array $waiting = [];

    private int $tickets = 0;

    /**
     * @param int<0, max> $maxWaiting
     */
    public function __construct(
        private readonly int $maxWaiting = self::MAX_WAITING,
    ) {}

    /**
     * @template T
     *
     * @param \Closure(): T $build
     *
     * @return T
     *
     * @throws BuildQueueFull When the waiting line is full.
     * @throws \LogicException When the fiber holds the lock already: it would wait for itself.
     */
    public function run(\Closure $build): mixed
    {
        $locked = $this->acquire();
        try {
            return $build();
        } finally {
            if ($locked) {
                $this->taken = false;
                $this->holder = null;
            }
        }
    }

    /**
     * @return bool Whether the lock was taken, and must be released.
     */
    private function acquire(): bool
    {
        $fiber = \Fiber::getCurrent();
        $id = $fiber === null ? null : \spl_object_id($fiber);
        // Not kept: a waiting fiber that holds itself is not freed when its owner drops it
        unset($fiber);
        $this->taken && $id !== null && $this->holder === $id and throw new \LogicException(
            'The fiber holds the profile build lock already: the lock is not reentrant.',
        );

        if (!$this->taken && $this->waiting === []) {
            $this->holder = $id;
            return $this->taken = true;
        }

        if ($id === null) {
            return false;
        }

        \count($this->waiting) >= $this->maxWaiting and throw new BuildQueueFull(
            \sprintf('%d profile builds are waiting already.', \count($this->waiting)),
        );

        $ticket = ++$this->tickets;
        $this->waiting[] = $ticket;
        try {
            while ($this->taken || $this->waiting[0] !== $ticket) {
                \Fiber::suspend();
            }
        } finally {
            // Also when the fiber is destroyed while it waits
            $this->waiting = \array_values(\array_diff($this->waiting, [$ticket]));
        }

        $this->holder = $id;
        return $this->taken = true;
    }
}
