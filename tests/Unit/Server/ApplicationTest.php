<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Server;

use Buggregator\Trap\Module\HttpDump\Module as HttpDumpModule;
use Buggregator\Trap\Server\Application;
use Buggregator\Trap\Server\Application\Module\Modules;
use Buggregator\Trap\Server\Bootstrap;
use Buggregator\Trap\Server\Config\SocketServer;
use Buggregator\Trap\Server\Handler\Http\Handler\MiddlewareHandler;
use Buggregator\Trap\Server\Handler\Http\Middleware;
use Buggregator\Trap\Server\Handler\Http\RequestHandler;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Server\Sender;
use Buggregator\Trap\Server\Sender\HttpEndpoint;
use Buggregator\Trap\Server\Sender\ServesHttp;
use Buggregator\Trap\Server\Traffic\Dispatcher\Http;
use Buggregator\Trap\Server\Traffic\Inspector;
use Buggregator\Trap\Server\Traffic\Parser;
use Buggregator\Trap\Server\Traffic\StreamClient;
use Buggregator\Trap\Tests\Unit\Module\Frontend\Stub\TakingHttpUi;
use Buggregator\Trap\Tests\Unit\Server\Event\Stub\EventSender;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Testo\Assert;
use Testo\Assert\Api\ExpectedException;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
use Testo\Expect;
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

    public function testEndpointsOnOwnPortAreMergedInSenderOrder(): void
    {
        [$first, $second] = [self::middleware(), self::middleware()];
        [$firstHandler, $secondHandler] = [self::handler(), self::handler()];

        $app = $this->application([], [
            self::endpointSender(9999, middlewares: [$first], handlers: [$firstHandler]),
            self::endpointSender(9999, middlewares: [$second], handlers: [$secondHandler]),
        ]);
        $pipelines = self::httpPipelines($app);
        $app->destroy();

        // The core inspector, then the single one of the endpoint port
        Assert::count($pipelines, 2);
        Assert::same($pipelines[1], [[$firstHandler, $secondHandler], [$first, $second]]);
    }

    public function testEndpointsOnTcpPortAreMergedWhateverTheirHosts(): void
    {
        [$first, $second] = [self::middleware(), self::middleware()];

        $app = $this->application([new SocketServer(port: 9912)], [
            self::endpointSender(9912, '127.0.0.1', [$first]),
            self::endpointSender(9912, '0.0.0.0', [$second]),
        ]);
        $pipelines = self::httpPipelines($app);
        $app->destroy();

        Assert::count($pipelines, 2);
        Assert::same(\array_slice($pipelines[1][1], 0, 2), [$first, $second]);
    }

    public function testEndpointsOnOwnPortWithDifferentHostsFail(): never
    {
        Expect::exception(\LogicException::class)
            ->withMessageContaining('HTTP endpoints on port 9999 take different hosts: `127.0.0.1` of ');

        $this->application([], [
            self::endpointSender(9999, '127.0.0.1'),
            self::endpointSender(9999, null),
            self::endpointSender(9999, '0.0.0.0'),
        ]);
    }

    #[DataSet([null, '0.0.0.0'], 'unset first')]
    #[DataSet(['0.0.0.0', null], 'unset last')]
    #[DataSet([null, null], 'both unset')]
    public function testEndpointWithoutHostTakesTheHostOfThePort(?string $first, ?string $second): void
    {
        $app = $this->application([], [
            self::endpointSender(9999, $first),
            self::endpointSender(9999, null),
            self::endpointSender(9999, $second),
        ]);

        $endpoints = (new \ReflectionMethod($app, 'httpEndpoints'))->invoke($app, []);
        $app->destroy();

        Assert::same($endpoints[9999]->host, $first ?? $second);
    }

    public function testEventMapperConflictFailsAtStartup(): never
    {
        // The container wraps the exception of each object it fails to create
        Expect::exception(\RuntimeException::class)
            ->withMessageContaining('EventCollector')
            ->withPrevious(\RuntimeException::class, static fn(ExpectedException $mapper) => $mapper
                ->withMessageContaining('FrameMapper')
                ->withPrevious(\LogicException::class, static fn(ExpectedException $conflict) => $conflict
                    ->withMessageContaining('is already given by')));

        $this->application([], [new EventSender()], modules: [HttpDumpModule::class, TakingHttpUi::class]);
    }

    /**
     * @param list<Middleware> $middlewares
     * @param list<RequestHandler> $handlers
     */
    private static function endpointSender(
        int $port,
        ?string $host = '127.0.0.1',
        array $middlewares = [],
        array $handlers = [],
    ): Sender&ServesHttp {
        return new class($port, $host, $middlewares, $handlers) implements Sender, ServesHttp {
            public function __construct(
                private readonly int $port,
                private readonly ?string $host,
                private readonly array $middlewares,
                private readonly array $handlers,
            ) {}

            public function send(iterable $frames): void {}

            public function httpEndpoint(): HttpEndpoint
            {
                return new HttpEndpoint($this->port, $this->host, $this->middlewares, $this->handlers);
            }
        };
    }

    private static function middleware(): Middleware
    {
        return new class implements Middleware {
            public function handle(ServerRequestInterface $request, callable $next): ResponseInterface
            {
                return $next($request);
            }
        };
    }

    private static function handler(): RequestHandler
    {
        return new class implements RequestHandler {
            public function handle(StreamClient $streamClient, ServerRequestInterface $request, callable $next): iterable
            {
                return $next($streamClient, $request);
            }
        };
    }

    private static function read(object $object, string $property): mixed
    {
        return (new \ReflectionProperty($object, $property))->getValue($object);
    }

    /**
     * @return list<array{list<object>, list<object>}> The request handlers before the middleware one
     *         and the middlewares of each HTTP dispatcher of the application.
     */
    private static function httpPipelines(Application $app): array
    {
        $pipelines = [];
        foreach (self::httpDispatchers($app) as $dispatcher) {
            $handlers = self::read(self::read($dispatcher, 'pipeline'), 'middlewares');
            $last = \array_pop($handlers);
            \assert($last instanceof MiddlewareHandler);
            $pipelines[] = [$handlers, self::read(self::read($last, 'pipeline'), 'middlewares')];
        }

        return $pipelines;
    }

    /**
     * @return list<Http>
     */
    private static function httpDispatchers(Application $app): array
    {
        $dispatchers = [];
        foreach (self::read($app, 'processors') as $processor) {
            if (!$processor instanceof Inspector) {
                continue;
            }
            foreach (self::read($processor, 'dispatchers') as $dispatcher) {
                $dispatcher instanceof Http and $dispatchers[] = $dispatcher;
            }
        }

        return $dispatchers;
    }

    /**
     * @param list<SocketServer> $map
     * @param list<Sender> $senders
     * @param list<class-string> $modules
     */
    private function application(
        array $map,
        array $senders,
        array $environment = [],
        array $modules = [],
    ): Application {
        $container = Bootstrap::init()->withConfig(environment: $environment)->finish();
        $this->output = new BufferedOutput();
        $container->set(new Logger($this->output));
        $container->set(new Modules($container, $modules));

        return new Application($container, $map, $senders);
    }

    /**
     * @return list<int> The limit of each HTTP dispatcher of the application.
     */
    private function gzipLimits(string $env): array
    {
        $app = $this->application(
            [new SocketServer(port: 9912)],
            [self::endpointSender(9912), self::endpointSender(9999)],
            ['TRAP_HTTP_MAX_INFLATED_SIZE' => $env],
        );

        $limits = [];
        foreach (self::httpDispatchers($app) as $dispatcher) {
            $limits[] = self::read(self::read($dispatcher, 'parser'), 'maxInflatedBodySize');
        }
        $app->destroy();

        return $limits;
    }
}
