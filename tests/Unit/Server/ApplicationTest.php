<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server;

use Buggregator\Trap\Server\Application;
use Buggregator\Trap\Server\Application\Module\Modules;
use Buggregator\Trap\Server\Bootstrap;
use Buggregator\Trap\Server\Config\SocketServer;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Server\Sender;
use Buggregator\Trap\Server\Sender\HttpEndpoint;
use Buggregator\Trap\Server\Sender\ServesHttp;
use Buggregator\Trap\Server\Traffic\Dispatcher\Http;
use Buggregator\Trap\Server\Traffic\Inspector;
use Buggregator\Trap\Server\Traffic\Parser;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Test;

#[Test]
#[Covers(Application::class)]
final class ApplicationTest
{
    private BufferedOutput $output;

    #[DataSet(['1024', 1024], 'bytes')]
    #[DataSet(['2k', 2048], 'lower case K')]
    #[DataSet(['3M', 3 * 1024 * 1024], 'M')]
    #[DataSet([' 1G ', 1024 * 1024 * 1024], 'G with spaces')]
    public function testEveryHttpDispatcherTakesTheConfiguredGzipLimit(string $env, int $bytes): void
    {
        $limits = $this->gzipLimits($env);

        // The core dispatcher, an endpoint sharing a TCP port, and an endpoint on its own port
        Assert::same($limits, [$bytes, $bytes, $bytes]);
        Assert::same($this->output->fetch(), '');
    }

    #[DataSet(['67_108_864'], 'underscores')]
    #[DataSet(['64MB'], 'unit suffix')]
    #[DataSet(['lots'], 'garbage')]
    #[DataSet(['0'], 'zero')]
    #[DataSet(['-1'], 'minus one')]
    #[DataSet([''], 'empty')]
    public function testInvalidGzipLimitFallsBackToDefaultWithWarning(string $env): void
    {
        $default = Parser\Http::MAX_INFLATED_BODY_SIZE;

        Assert::same($this->gzipLimits($env), [$default, $default, $default]);
        Assert::string($this->output->fetch())->contains("TRAP_HTTP_MAX_INFLATED_SIZE `$env` is not a size");
    }

    private static function endpointSender(int $port): Sender&ServesHttp
    {
        return new class($port) implements Sender, ServesHttp {
            public function __construct(
                private readonly int $port,
            ) {}

            public function send(iterable $frames): void {}

            public function httpEndpoint(): HttpEndpoint
            {
                return new HttpEndpoint($this->port, '127.0.0.1', [], []);
            }
        };
    }

    private static function read(object $object, string $property): mixed
    {
        return (new \ReflectionProperty($object, $property))->getValue($object);
    }

    /**
     * @return list<int> The limit of each HTTP dispatcher of the application.
     */
    private function gzipLimits(string $env): array
    {
        $container = Bootstrap::init()
            ->withConfig(environment: ['TRAP_HTTP_MAX_INFLATED_SIZE' => $env])
            ->finish();
        $this->output = new BufferedOutput();
        $container->set(new Logger($this->output));
        $container->set(new Modules($container, []));

        $app = new Application(
            $container,
            [new SocketServer(port: 9912)],
            [self::endpointSender(9912), self::endpointSender(9999)],
        );

        $limits = [];
        foreach (self::read($app, 'processors') as $processor) {
            if (!$processor instanceof Inspector) {
                continue;
            }
            foreach (self::read($processor, 'dispatchers') as $dispatcher) {
                if ($dispatcher instanceof Http) {
                    $limits[] = self::read(self::read($dispatcher, 'parser'), 'maxInflatedBodySize');
                }
            }
        }
        $app->destroy();

        return $limits;
    }
}
