<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Support;

use Buggregator\Trap\Support\Activity;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Covers(Activity::class)]
final class ActivityTest
{
    #[BeforeTest]
    #[AfterTest]
    public function forgetActivity(): void
    {
        Activity::take();
    }

    #[Test]
    public function takesReportOnce(): void
    {
        Activity::report();
        Activity::report();

        Assert::true(Activity::take());
        Assert::false(Activity::take());
    }

    #[Test]
    public function hasNoReportAtFirst(): void
    {
        Assert::false(Activity::take());
    }
}
