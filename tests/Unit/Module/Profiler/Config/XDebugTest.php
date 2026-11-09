<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Profiler\Config;

use Buggregator\Trap\Bootstrap;
use Buggregator\Trap\Module\Profiler\Config\XDebug;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Covers(XDebug::class)]
#[Test]
final class XDebugTest
{
    #[DataSet(['profile'], 'profile')]
    #[DataSet(['develop,profile'], 'among other modes')]
    #[DataSet([' debug , profile '], 'with spaces')]
    public function testObservesOutputDirWhileProfiling(string $mode): void
    {
        $config = new XDebug();
        $config->mode = $mode;
        $config->outputDir = '/tmp/xdebug';

        Assert::true($config->isValid());
        Assert::same($config->path, '/tmp/xdebug');
    }

    #[DataSet([null], 'no Xdebug')]
    #[DataSet(['off'], 'off')]
    #[DataSet(['develop'], 'develop')]
    #[DataSet(['debug,trace'], 'other modes')]
    #[DataSet(['profiler'], 'not a mode')]
    public function testIgnoresOutputDirWithoutProfiling(?string $mode): void
    {
        $config = new XDebug();
        $config->mode = $mode;
        $config->outputDir = '/tmp/xdebug';

        Assert::false($config->isValid());
        Assert::null($config->path);
    }

    public function testExplicitPathWinsOverOutputDir(): void
    {
        $config = new XDebug();
        $config->path = '/srv/profiles';
        $config->mode = 'off';
        $config->outputDir = '/tmp/xdebug';

        Assert::true($config->isValid());
        Assert::same($config->path, '/srv/profiles');
    }

    public function testPathFromEnv(): void
    {
        $config = $this->config(['TRAP_XDEBUG_PATH' => '/srv/profiles', 'XDEBUG_MODE' => 'off']);

        Assert::true($config->isValid());
        Assert::same($config->path, '/srv/profiles');
    }

    public function testModeEnvOverridesIni(): void
    {
        Assert::same($this->config(['XDEBUG_MODE' => 'debug,trace'])->mode, 'debug,trace');
        Assert::false($this->config(['XDEBUG_MODE' => 'debug,trace'])->isValid());
    }

    public function testProfileModeEnvObservesIniOutputDir(): void
    {
        $outputDir = \ini_get('xdebug.output_dir') ?: null;

        $config = $this->config(['XDEBUG_MODE' => 'profile']);

        Assert::same($config->isValid(), $outputDir !== null);
        Assert::same($config->path, $outputDir);
    }

    /**
     * @param array<string, string> $env
     */
    private function config(array $env): XDebug
    {
        return Bootstrap::init()->withConfig(environment: $env)->finish()->get(XDebug::class);
    }
}
