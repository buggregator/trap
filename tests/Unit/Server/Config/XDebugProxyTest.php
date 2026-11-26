<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Config;

use Buggregator\Trap\Server\Application\Config\Internal\ConfigInflector;
use Buggregator\Trap\Server\Config\XDebugProxy;
use Internal\Container\ObjectContainer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Test]
#[Covers(XDebugProxy::class)]
final class XDebugProxyTest
{
    #[DataSet([[], false], 'unset')]
    #[DataSet([['XDEBUG_PROXY_ENABLED' => 'true'], true], 'true')]
    #[DataSet([['XDEBUG_PROXY_ENABLED' => '1'], true], 'one')]
    #[DataSet([['XDEBUG_PROXY_ENABLED' => 'yes'], true], 'yes')]
    #[DataSet([['XDEBUG_PROXY_ENABLED' => 'false'], false], 'false')]
    #[DataSet([['XDEBUG_PROXY_ENABLED' => '0'], false], 'zero')]
    public function testEnabledByEnv(array $env, bool $enabled): void
    {
        $config = new XDebugProxy();
        (new ConfigInflector(env: $env))->inflect($config, new ObjectContainer());

        Assert::same($config->enabled, $enabled);
    }
}
