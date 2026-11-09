<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Ray\Lock;

use Buggregator\Trap\Module\Ray\Lock\Lock;
use Buggregator\Trap\Module\Ray\Lock\Locks;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(Locks::class)]
#[Covers(Lock::class)]
#[Test]
final class LocksTest
{
    public function testCreatedLockIsPending(): void
    {
        $locks = new Locks();
        $locks->create('abc', 100.0);

        $lock = $locks->poll('abc', 101.0);

        Assert::true($lock?->isPending());
        Assert::null($lock?->stop);
    }

    public function testUnknownLock(): void
    {
        $locks = new Locks();

        Assert::null($locks->poll('abc', 100.0));
        Assert::false($locks->resolve('abc', true, 100.0));
        Assert::false($locks->has('abc'));
    }

    public function testContinue(): void
    {
        $locks = new Locks();
        $locks->create('abc', 100.0);

        Assert::true($locks->resolve('abc', false, 101.0));
        $lock = $locks->poll('abc', 102.0);

        Assert::false($lock?->isPending());
        Assert::false($lock?->stop);
    }

    public function testStop(): void
    {
        $locks = new Locks();
        $locks->create('abc', 100.0);

        Assert::true($locks->resolve('abc', true, 101.0));

        Assert::true($locks->poll('abc', 102.0)?->stop);
    }

    public function testResolvesOnlyOnce(): void
    {
        $locks = new Locks();
        $locks->create('abc', 100.0);
        $locks->resolve('abc', true, 101.0);

        Assert::false($locks->resolve('abc', false, 102.0));
        Assert::true($locks->poll('abc', 103.0)?->stop);
    }

    public function testPendingLockExpiresWithoutPolls(): void
    {
        $locks = new Locks();
        $locks->create('abc', 100.0);

        Assert::notNull($locks->poll('abc', 100.0 + Locks::IDLE_TTL));
        Assert::null($locks->poll('abc', 100.0 + 2 * Locks::IDLE_TTL + 1));
        Assert::same($locks->count(), 0);
    }

    public function testPollsKeepPendingLockAlive(): void
    {
        $locks = new Locks();
        $locks->create('abc', 0.0);

        for ($now = 1.0; $now < 10 * Locks::IDLE_TTL; ++$now) {
            $locks->poll('abc', $now);
        }

        Assert::true($locks->poll('abc', $now)?->isPending());
    }

    public function testResolvedLockExpires(): void
    {
        $locks = new Locks();
        $locks->create('abc', 100.0);
        $locks->resolve('abc', false, 100.0);

        Assert::notNull($locks->poll('abc', 100.0 + Locks::RESOLVED_TTL));
        // Polls don't extend a resolved lock
        Assert::null($locks->poll('abc', 100.0 + Locks::RESOLVED_TTL + 1));
    }

    public function testExpiredLocksAreDroppedOnAnyCall(): void
    {
        $locks = new Locks();
        $locks->create('a', 100.0);
        $locks->create('b', 100.0 + Locks::IDLE_TTL + 1);

        Assert::false($locks->has('a'));
        Assert::true($locks->has('b'));
    }

    public function testDropsOldestLockOverLimit(): void
    {
        $locks = new Locks();
        for ($i = 0; $i <= Locks::MAX_LOCKS; ++$i) {
            // Numeric names become integer array keys
            $locks->create((string) $i, 100.0);
        }

        Assert::same($locks->count(), Locks::MAX_LOCKS);
        Assert::false($locks->has('0'));
        Assert::true($locks->has('1'));
        Assert::true($locks->has((string) Locks::MAX_LOCKS));
    }

    public function testIgnoresInvalidNames(): void
    {
        $locks = new Locks();
        $locks->create('../x', 100.0);
        $locks->create('', 100.0);
        $locks->create(\str_repeat('a', 129), 100.0);

        Assert::same($locks->count(), 0);
        Assert::true(Locks::isValidName('5d41402abc4b2a76b9719d911017c592'));
    }
}
