<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\Frontend\Sender;

use Buggregator\Trap\Module\Frontend\Sender\FrontendSender;
use Buggregator\Trap\Server\Application\Module\Modules;
use Buggregator\Trap\Server\Bootstrap;
use Buggregator\Trap\Server\Logger;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Test]
#[Covers(FrontendSender::class)]
final class FrontendSenderTest
{
    #[DataSet(['0.0.0.0', 'http://0.0.0.0:8000'], 'any IPv4')]
    #[DataSet(['::', 'http://[::]:8000'], 'any IPv6')]
    public function testWarnsOfHostBeyondLoopback(string $host, string $url): void
    {
        $output = new BufferedOutput();

        self::sender($output, ['TRAP_MCP_TOKEN' => 's3cr3t'])->listensOn($host);

        Assert::string($output->fetch())->contains("Web UI: $url listens beyond the loopback")->notContains('s3cr3t');
    }

    #[DataSet(['127.0.0.1'], 'IPv4 loopback')]
    #[DataSet(['[::1]'], 'IPv6 loopback')]
    #[DataSet(['localhost'], 'localhost')]
    public function testDoesNotWarnOfLoopback(string $host): void
    {
        $output = new BufferedOutput();

        self::sender($output)->listensOn($host);

        Assert::same($output->fetch(), '');
    }

    /**
     * @param array<non-empty-string, string> $environment
     */
    private static function sender(BufferedOutput $output, array $environment = []): FrontendSender
    {
        $container = Bootstrap::init()->withConfig(environment: $environment)->finish();
        $container->set(new Modules($container));
        $container->set(new Logger($output));

        return $container->get(FrontendSender::class);
    }
}
