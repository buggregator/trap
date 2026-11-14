<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Support;

use Buggregator\Trap\Support\Timer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(Timer::class)]
final class TimerTest
{
    public function isNotReadyBeforeTheBeep(): void
    {
        $timer = new Timer(3600.0);

        Assert::false($timer->isReady());
        Assert::false($timer->isStopped());
    }

    public function isReadyAfterTheBeep(): void
    {
        $timer = new Timer(0.001);

        \usleep(5_000);

        Assert::true($timer->isReady());
        Assert::true($timer->elapsed() > 0.001);
    }

    public function isNeverReadyWithoutBeepAndCondition(): void
    {
        $timer = new Timer();

        \usleep(1_000);

        Assert::false($timer->isReady());
    }

    public function stoppedTimerIsNotReady(): void
    {
        $timer = new Timer(0.001);
        \usleep(5_000);

        $timer->stop();

        Assert::true($timer->isStopped());
        Assert::false($timer->isReady());
    }

    public function stoppedTimerHasNoElapsedTime(): never
    {
        $timer = (new Timer(1.0))->stop();

        Expect::exception(\RuntimeException::class)->withMessage('Timer stopped.');
        $timer->elapsed();
    }

    public function conditionMakesTimerReady(): void
    {
        $met = false;
        $timer = (new Timer(3600.0, static function () use (&$met): bool {
            return $met;
        }))->stop();
        Assert::false($timer->isReady());

        $met = true;

        Assert::true($timer->isReady());
    }

    public function continueRestartsOnlyStoppedTimer(): void
    {
        $timer = new Timer(0.001);
        \usleep(5_000);

        Assert::true($timer->continue()->isReady());

        $timer->stop();
        // A long beep: a restarted timer must not be ready however long the runner pauses
        $timer->beep = 3600.0;
        $timer->continue();

        Assert::false($timer->isStopped());
        Assert::false($timer->isReady());
    }

    public function waitSuspendsTheFiberUntilReady(): void
    {
        $met = false;
        $timer = new Timer(condition: static function () use (&$met): bool {
            return $met;
        });
        $fiber = new \Fiber($timer->wait(...));
        $fiber->start();
        $fiber->resume();
        Assert::true($fiber->isSuspended());

        $met = true;
        $fiber->resume();

        Assert::true($fiber->isTerminated());
        Assert::same($fiber->getReturn(), $timer);
    }
}
