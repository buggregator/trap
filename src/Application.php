<?php

declare(strict_types=1);

namespace Buggregator\Trap;

use Buggregator\Trap\Application\Module\Modules;
use Buggregator\Trap\Config\Server\App;
use Buggregator\Trap\Config\Server\Frontend as FrontendConfig;
use Buggregator\Trap\Config\Server\SocketServer;
use Buggregator\Trap\Config\Server\TcpPorts;
use Buggregator\Trap\Handler\Http\Handler\Websocket;
use Buggregator\Trap\Handler\Http\Middleware;
use Buggregator\Trap\Handler\Http\ProvidesHttpMiddlewares;
use Buggregator\Trap\Proto\Buffer;
use Buggregator\Trap\Service\FilesObserver\ProvidesFileObservers;
use Buggregator\Trap\Socket\Client;
use Buggregator\Trap\Socket\Server;
use Buggregator\Trap\Socket\SocketStream;
use Buggregator\Trap\Support\Timer;
use Buggregator\Trap\Traffic\Inspector;
use Internal\Container\Container;
use Internal\Destroy\Destroyable;

/**
 * @internal
 */
final class Application implements Processable, Cancellable, Destroyable
{
    /** @var Processable[] */
    private array $processors = [];

    /** @var Server[] */
    private array $servers = [];

    /** @var \Fiber[] Any tasks in fibers */
    private array $fibers = [];

    private readonly Buffer $buffer;
    private bool $cancelled = false;
    private readonly Logger $logger;

    /**
     * @param SocketServer[] $map
     * @param Sender[] $senders
     */
    public function __construct(
        private readonly Container $container,
        array $map = [],
        private array $senders = [],
        bool $withFrontend = true,
    ) {
        $this->logger = $this->container->get(Logger::class);
        $this->buffer = new Buffer(bufferSize: 10485760, timer: 0.1);
        $this->container->set($this->buffer);

        // Frontend
        $feConfig = $this->container->get(FrontendConfig::class);
        $feSeparated = !\in_array(
            $feConfig->port,
            \array_map(static fn(SocketServer $item): ?int => $item->protocol === 'tcp' ? $item->port : null, $map),
            true,
        );
        $withFrontend and $this->configureFrontend($feSeparated);

        $modules = $this->container->get(Modules::class);
        $middlewares = [
            $this->container->get(Middleware\Resources::class),
            $this->container->get(Middleware\DebugPage::class),
            ...$this->httpMiddlewares($modules),
        ];

        $inspectorWithFrontend = $inspector = $container->make(Inspector::class, [
            'dispatchers' => $this->dispatchers(
                $modules,
                new Traffic\Dispatcher\Http($middlewares, [new Websocket()]),
            ),
        ]);
        $this->processors[] = $inspector;

        if ($withFrontend && !$feSeparated) {
            $inspectorWithFrontend = $container->make(Inspector::class, [
                'dispatchers' => $this->dispatchers(
                    $modules,
                    new Traffic\Dispatcher\Http(
                        [$this->container->get(Module\Frontend\Http\Pipeline::class), ...$middlewares],
                        [$this->container->get(Module\Frontend\Http\RequestHandler::class)],
                    ),
                ),
            ]);
            $this->processors[] = $inspectorWithFrontend;
        }

        $this->configureFileObserver($modules);

        foreach ($map as $config) {
            $withFrontend && !$feSeparated && $config->protocol === 'tcp' && $config->port === $feConfig->port
                ? $this->prepareServerFiber($config, $inspectorWithFrontend, $this->logger)
                : $this->prepareServerFiber($config, $inspector, $this->logger);
        }
    }

    public function run(): void
    {
        /** @var App $config */
        $config = $this->container->get(App::class);
        $sleep = \max(50, $config->mainLoopInterval);
        foreach ($this->senders as $sender) {
            \assert($sender instanceof Sender);
            if ($sender instanceof Processable) {
                $this->processors[] = $sender;
            }
        }

        while (true) {
            $this->process($this->senders);
            \usleep($sleep);
        }
    }

    /**
     * @param Sender[] $senders
     */
    #[\Override]
    public function process(array $senders = []): void
    {
        foreach ($this->processors as $server) {
            $server->process();
        }

        // Process buffer
        if ($this->buffer->isReady()) {
            $this->sendBuffer($senders);
        }

        foreach ($this->fibers as $key => $fiber) {
            try {
                $fiber->isStarted() ? $fiber->resume() : $fiber->start();

                if ($fiber->isTerminated()) {
                    unset($this->fibers[$key]);
                }
            } catch (\Throwable $e) {
                $this->logger->exception($e);
                unset($this->fibers[$key]);
            }
        }
    }

    #[\Override]
    public function destroy(): void
    {
        foreach ([...$this->servers, ...$this->processors] as $instance) {
            if ($instance instanceof Destroyable) {
                $instance->destroy();
            }
        }

        $this->servers = [];
        $this->processors = [];
        $this->fibers = [];
    }

    #[\Override]
    public function cancel(): void
    {
        $this->cancelled = true;
        $this->fibers[] = new \Fiber(
            function (): void {
                foreach ($this->servers as $server) {
                    $server->cancel();
                }
            },
        );
        foreach ($this->processors as $processor) {
            if ($processor instanceof Cancellable) {
                $processor->cancel();
            }
        }
    }

    private function prepareServerFiber(SocketServer $config, Inspector $inspector, Logger $logger): \Fiber
    {
        return $this->fibers[] = new \Fiber(function () use ($config, $inspector, $logger): void {
            do {
                try {
                    $this->processors[] = $this->servers[$config->port] = $this->createServer($config, $inspector);
                    return;
                } catch (\Throwable) {
                    $logger->error("Can't create TCP socket on port $config->port.");
                    (new Timer(1.0))->wait();
                }
            } while (!$this->cancelled);
        });
    }

    private function configureFrontend(bool $separated): void
    {
        $this->processors[] = $this->senders[] = $wsSender = $this->container->get(Module\Frontend\Sender\FrontendSender::class);
        $this->container->set($wsSender);
        $this->container->set($wsSender->getEventStorage());
        $this->container->set($wsSender->getConnectionPool());
        $this->container->get(Module\Frontend\UiChannel::class)->enable();

        if (!$separated) {
            return;
        }

        // Separated port
        $inspector = $this->container->make(Inspector::class, [
            'dispatchers' => [
                new Traffic\Dispatcher\Http(
                    [$this->container->get(Module\Frontend\Http\Pipeline::class)],
                    [$this->container->get(Module\Frontend\Http\RequestHandler::class)],
                    silentMode: true,
                ),
            ],
        ]);
        $this->processors[] = $inspector;
        /** @var TcpPorts $tcpConfig */
        $tcpConfig = $this->container->get(TcpPorts::class);
        $config = $this->container->get(FrontendConfig::class);
        $this->prepareServerFiber(
            new SocketServer(port: $config->port, host: $config->host, pollingInterval: $tcpConfig->pollingInterval),
            $inspector,
            $this->logger,
        );
    }

    /**
     * @param Sender[] $senders
     */
    private function sendBuffer(array $senders = []): void
    {
        $data = $this->buffer->getAndClean();

        foreach ($senders as $sender) {
            $this->fibers[] = new \Fiber(
                static fn() => $sender->send($data),
            );
        }
    }

    private function createServer(SocketServer $config, Inspector $inspector): Server
    {
        $logger = $this->logger;
        $clientInflector = static function (Client $client, int $id) use ($inspector, $logger): Client {
            $logger->debug('Client %d connected', $id);
            $inspector->addStream(SocketStream::create($client, $id));
            return $client;
        };

        return Server::init(
            $config->protocol,
            $config->host,
            $config->port,
            payloadSize: 524_288,
            acceptPeriod: (float) \max(50, $config->pollingInterval) / 1e6,
            clientInflector: $clientInflector,
            logger: $this->logger,
        );
    }

    /**
     * The core HTTP dispatcher goes before the modules' ones: the first dispatcher to recognize a stream
     * takes it, and no module protocol can be taken for HTTP. {@see Traffic\Dispatcher\Binary} is the
     * inspector's own fallback.
     *
     * @return list<Traffic\Dispatcher>
     */
    private function dispatchers(Modules $modules, Traffic\Dispatcher\Http $http): array
    {
        $dispatchers = [$http];
        foreach ($modules->implementing(Traffic\ProvidesDispatchers::class) as $module) {
            foreach ($module->dispatchers() as $class) {
                $dispatchers[] = $this->container->make($class);
            }
        }

        return $dispatchers;
    }

    /**
     * @return list<Middleware>
     */
    private function httpMiddlewares(Modules $modules): array
    {
        $middlewares = [];
        foreach ($modules->implementing(ProvidesHttpMiddlewares::class) as $module) {
            foreach ($module->httpMiddlewares() as $class) {
                $middlewares[] = $this->container->get($class);
            }
        }

        return $middlewares;
    }

    private function configureFileObserver(Modules $modules): void
    {
        $configs = [];
        foreach ($modules->implementing(ProvidesFileObservers::class) as $module) {
            foreach ($module->fileObserverConfigs() as $class) {
                $configs[] = $this->container->get($class);
            }
        }

        $this->processors[] = $this->container->make(Service\FilesObserver::class, ['configs' => $configs]);
    }
}
