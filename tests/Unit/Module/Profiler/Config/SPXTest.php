<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Config;

use Buggregator\Trap\Module\Profiler\Config\SPX;
use Buggregator\Trap\Module\Profiler\FilesObserver\SPX as Converter;
use Buggregator\Trap\Server\Bootstrap;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(SPX::class)]
#[Test]
final class SPXTest
{
    public function testPathFromEnv(): void
    {
        $config = Bootstrap::init()->withConfig(environment: ['TRAP_SPX_PATH' => '/srv/spx'])->finish()->get(SPX::class);

        Assert::true($config->isValid());
        Assert::same($config->path, '/srv/spx');
        Assert::same($config->converterClass, Converter::class);
    }

    public function testFallsBackToDataDirOfLoadedExtension(): void
    {
        $config = Bootstrap::init()->withConfig(environment: [])->finish()->get(SPX::class);

        Assert::same($config->path, \ini_get('spx.data_dir') ?: null);
    }

    public function testSkipsReportsWrittenBeforeStart(): void
    {
        Assert::true((new SPX())->skipOldFiles);
    }
}
