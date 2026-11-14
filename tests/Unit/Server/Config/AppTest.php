<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server\Config;

use Buggregator\Trap\Server\Application\Config\Internal\ConfigInflector;
use Buggregator\Trap\Server\Config\App;
use Internal\Container\ObjectContainer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(App::class)]
final class AppTest
{
    public function testHttpMaxInflatedSizeDefaultsTo64Megabytes(): void
    {
        $config = self::inflect([]);

        Assert::same($config->httpMaxInflatedSize, 64 * 1024 * 1024);
    }

    public function testHttpMaxInflatedSizeIsReadFromEnv(): void
    {
        $config = self::inflect(['TRAP_HTTP_MAX_INFLATED_SIZE' => '1048576']);

        Assert::same($config->httpMaxInflatedSize, 1_048_576);
    }

    /**
     * @param array<string, string> $env
     */
    private static function inflect(array $env): App
    {
        $config = new App();
        (new ConfigInflector(env: $env))->inflect($config, new ObjectContainer());

        return $config;
    }
}
