<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Ray\Lock;

/**
 * Execution locks of the paused Ray clients, by lock name.
 *
 * A container singleton: the Ray HTTP middleware creates locks and answers the clients' polls,
 * the UI controller resolves them. Expired locks are dropped on every call, so nothing needs a timer.
 *
 * @internal
 */
final class Locks
{
    /**
     * A pending lock is dropped when its client hasn't polled for this long. A client polls every second
     * while it waits, so a silent one is gone: killed, or out of execution time.
     */
    public const IDLE_TTL = 60.0;

    /**
     * A resolved lock stays for this long, so a repeated poll gets the same answer.
     */
    public const RESOLVED_TTL = 10.0;

    /**
     * Bound against clients that create locks and never poll. The oldest locks go first.
     */
    public const MAX_LOCKS = 1000;

    /** @var array<non-empty-string, Lock> In creation order. */
    private array $locks = [];

    /**
     * Lock names are sent back in URL paths: `locks/{name}` and `api/ray/locks/{name}`.
     */
    public static function isValidName(string $name): bool
    {
        return \preg_match('/^[\w.-]{1,128}$/D', $name) === 1;
    }

    /**
     * spatie/ray names a lock `md5(time())`, so clients pausing in the same second send the same name, and
     * their polls can't be told apart. They share one lock: a later create joins the existing lock, pending
     * or resolved, and one Continue or Stop releases them all. Replacing it instead would undo a Stop.
     *
     * @param float|null $now Unix time in seconds; the current time when null.
     */
    public function create(string $name, ?float $now = null): void
    {
        if (!self::isValidName($name)) {
            return;
        }

        $now ??= \microtime(true);
        $this->purge($now);
        $existing = $this->locks[$name] ?? null;
        if ($existing !== null) {
            $existing->isPending() and $existing->seenAt = $now;
            return;
        }

        /** @var non-empty-string $name */
        $this->locks[$name] = new Lock($now);
        // Not array_shift(): it renumbers names like "123", which PHP keeps as integer keys
        if (\count($this->locks) > self::MAX_LOCKS) {
            unset($this->locks[\array_key_first($this->locks)]);
        }
    }

    /**
     * The client's poll: refreshes a pending lock.
     *
     * @return Lock|null Null for an unknown or expired lock.
     */
    public function poll(string $name, ?float $now = null): ?Lock
    {
        $now ??= \microtime(true);
        $this->purge($now);
        $lock = $this->locks[$name] ?? null;
        $lock?->isPending() and $lock->seenAt = $now;

        return $lock;
    }

    /**
     * @return bool False for an unknown, expired or already resolved lock.
     */
    public function resolve(string $name, bool $stop, ?float $now = null): bool
    {
        $now ??= \microtime(true);
        $this->purge($now);
        $lock = $this->locks[$name] ?? null;
        if ($lock === null || !$lock->isPending()) {
            return false;
        }

        $lock->stop = $stop;
        $lock->resolvedAt = $now;

        return true;
    }

    public function has(string $name): bool
    {
        return isset($this->locks[$name]);
    }

    /**
     * @return int<0, max>
     */
    public function count(): int
    {
        return \count($this->locks);
    }

    private function purge(float $now): void
    {
        foreach ($this->locks as $name => $lock) {
            $expired = $lock->resolvedAt === null
                ? $now - $lock->seenAt > self::IDLE_TTL
                : $now - $lock->resolvedAt > self::RESOLVED_TTL;
            if ($expired) {
                unset($this->locks[$name]);
            }
        }
    }
}
