<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Client\Profiler\Internal;

use Buggregator\Trap\Client\Profiler\Internal\SpxExtension;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Core\Exception\SkipTest;
use Testo\Lifecycle\AfterTest;
use Testo\Test;

#[Test]
#[Covers(SpxExtension::class)]
final class SpxExtensionTest
{
    #[AfterTest]
    public function resetOverride(): void
    {
        SpxExtension::$override = null;
    }

    public function overrideReplacesTheExtension(): void
    {
        $override = new SpxExtension();
        SpxExtension::$override = $override;

        Assert::same(SpxExtension::get(), $override);
    }

    public function givesNewRealExtensionWithoutOverride(): void
    {
        Assert::notSame(SpxExtension::get(), SpxExtension::get());
    }

    public function isNotLoadedWithoutTheExtension(): void
    {
        \extension_loaded('spx') and throw new SkipTest('ext-spx is loaded.');

        $extension = SpxExtension::get();

        Assert::false($extension->isLoaded());
        Assert::same($extension->dataDir(), '/tmp/spx');
    }
}
