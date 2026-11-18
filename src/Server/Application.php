<?php

declare(strict_types=1);

namespace Buggregator\Trap\Server;

use Buggregator\Trap\Server\Application\Module\Modules;
use Buggregator\Trap\Server\Config\App;
use Buggregator\Trap\Server\Config\SocketServer;
use Buggregator\Trap\Server\Event\EventCollector;
use Buggregator\Trap\Server\Handler\Http\Middleware;
use Buggregator\Trap\Server\Handler\Http\ProvidesHttpMiddlewares;
use Buggregator\Trap\Server\Proto\Buffer;
use Buggregator\Trap\Server\Sender\ServesHttp;
use Buggregator\Trap\Server\Service\FilesObserver\ProvidesFileObservers;
use Buggregator\Trap\Server\Socket\Client;
use Buggregator\Trap\Server\Socket\Server;
use Buggregator\Trap\Server\Socket\SocketStream;
use Buggregator\Trap\Server\Traffic\Inspector;
use Buggregator\Trap\Support\Activity;
use Buggregator\Trap\Support\LoopPacer;
use Buggregator\Trap\Support\MemoryLimit;
use Buggregator\Trap\Support\Timer;
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

    /** Null when no sender consumes events: then frames are not mapped at all. */
    private readonly ?EventCollector $collector;

    /**
     * @param SocketServer[] $map
     * @param Sender[] $senders
     */
    public function __construct(
        private readonly Container $container,
        array $map = [],
        private array $senders = [],
    ) {
        $this->logger = $this->container->get(Logger::class);
        $this->buffer = new Buffer(bufferSize: 10485760, timer: 0.1);
        $this->container->set($this->buffer);

        $consumesEvents = false;
        foreach ($this->senders as $sender) {
            $sender instanceof Processable and $this->processors[] = $sender;
            $sender instanceof Sender\ConsumesEvents and $consumesEvents = true;
        }

        // Here, not at the first frame: a conflict of the modules' event mappers fails the startup
        $this->collector = $consumesEvents ? $this->container->get(EventCollector::class) : null;

        $maxInflated = $this->maxInflatedBodySize($this->container->get(App::class)->httpMaxInflatedSize);
        $modules = $this->container->get(Modules::class);
        $middlewares = [
            $this->container->get(Middleware\Resources::class),
            ...$this->httpMiddlewares($modules),
        ];

        $inspector = $container->make(Inspector::class, [
            'dispatchers' => $this->dispatchers(
                $modules,
                new Traffic\Dispatcher\Http($middlewares, maxInflatedBodySize: $maxInflated),
            ),
        ]);
        $this->processors[] = $inspector;

        $sharedPorts = $this->configureHttpEndpoints($map, $modules, $middlewares, $maxInflated);

        $this->configureFileObserver($modules);

        foreach ($map as $config) {
            $this->prepareServerFiber(
                $config,
                $config->protocol === 'tcp' ? $sharedPorts[$config->port] ?? $inspector : $inspector,
                $this->logger,
            );
        }
    }

    public function run(): void
    {
        /** @var App $config */
        $config = $this->container->get(App::class);
        $sleep = \max(50, $config->mainLoopInterval);
        $gc = $config->gcInterval > 0 ? new Timer($config->gcInterval) : null;
        $gc === null or \gc_disable();
        $pacer = new LoopPacer($sleep);

        while (true) {
            $this->process($this->senders);
            if ($gc?->isReady()) {
                \gc_collect_cycles();
                $gc->reset();
            }
            $ready = $pacer->pause(Activity::take(), $this->sockets(...));
            if ($ready !== []) {
                foreach ($this->servers as $server) {
                    $server->wake($ready);
                }
            }
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

    /**
     * Listens on the HTTP endpoints of the senders. Endpoints on one port are merged, see {@see ServesHttp}.
     *
     * @param SocketServer[] $map
     * @param list<Middleware> $middlewares
     *
     * @return array<int, Inspector> Inspectors for the listened TCP ports that an endpoint shares, by port.
     */
    private function configureHttpEndpoints(
        array $map,
        Modules $modules,
        array $middlewares,
        int $maxInflated,
    ): array {
        $tcpPorts = \array_map(
            static fn(SocketServer $item): ?int => $item->protocol === 'tcp' ? $item->port : null,
            $map,
        );
        $tcpHosts = [];
        foreach ($map as $server) {
            $server->protocol === 'tcp' and $tcpHosts[$server->port] = $server->host;
        }

        $shared = [];
        /** @var array<int, non-empty-string> $hosts The host each endpoint port listens on */
        $hosts = [];
        foreach ($this->httpEndpoints($tcpPorts) as $endpoint) {
            if (\in_array($endpoint->port, $tcpPorts, true)) {
                $hosts[$endpoint->port] = $tcpHosts[$endpoint->port] ?? Sender\HttpEndpoint::DEFAULT_HOST;
                $this->processors[] = $shared[$endpoint->port] = $this->container->make(Inspector::class, [
                    'dispatchers' => $this->dispatchers(
                        $modules,
                        new Traffic\Dispatcher\Http(
                            [...$endpoint->middlewares, ...$middlewares],
                            $endpoint->handlers,
                            maxInflatedBodySize: $maxInflated,
                        ),
                    ),
                ]);
                continue;
            }

            $inspector = $this->container->make(Inspector::class, [
                'dispatchers' => [
                    new Traffic\Dispatcher\Http(
                        $endpoint->middlewares,
                        $endpoint->handlers,
                        silentMode: true,
                        maxInflatedBodySize: $maxInflated,
                    ),
                ],
            ]);
            $this->processors[] = $inspector;
            $hosts[$endpoint->port] = $endpoint->host ?? Sender\HttpEndpoint::DEFAULT_HOST;
            $this->prepareServerFiber(
                new SocketServer(
                    port: $endpoint->port,
                    host: $hosts[$endpoint->port],
                ),
                $inspector,
                $this->logger,
            );
        }

        foreach ($this->senders as $sender) {
            $sender instanceof Sender\ObservesHttpHost
                and $sender->listensOn($hosts[$sender->httpEndpoint()->port] ?? Sender\HttpEndpoint::DEFAULT_HOST);
        }

        return $shared;
    }

    /**
     * Merges the HTTP endpoints of the senders by port, see {@see ServesHttp}.
     *
     * @param array<array-key, int|null> $tcpPorts
     *
     * @return array<int, Sender\HttpEndpoint> By port.
     */
    private function httpEndpoints(array $tcpPorts): array
    {
        /** @var array<int, Sender\HttpEndpoint> $endpoints */
        $endpoints = [];
        /** @var array<int, class-string> $owners Who gave the host of each port */
        $owners = [];
        foreach ($this->senders as $sender) {
            if (!$sender instanceof ServesHttp) {
                continue;
            }

            $endpoint = $sender->httpEndpoint();
            $port = $endpoint->port;
            $merged = $endpoints[$port] ?? null;
            if ($merged?->host === null) {
                $endpoint->host === null or $owners[$port] = $sender::class;
            } elseif ($endpoint->host !== null && $endpoint->host !== $merged->host) {
                // A TCP port listens on the TCP host whatever the endpoints take
                \in_array($port, $tcpPorts, true) or throw new \LogicException(\sprintf(
                    'HTTP endpoints on port %d take different hosts: `%s` of %s and `%s` of %s.',
                    $port,
                    $merged->host,
                    $owners[$port] ?? '',
                    $endpoint->host,
                    $sender::class,
                ));
            }

            $endpoints[$port] = $merged === null ? $endpoint : new Sender\HttpEndpoint(
                port: $port,
                host: $merged->host ?? $endpoint->host,
                middlewares: [...$merged->middlewares, ...$endpoint->middlewares],
                handlers: [...$merged->handlers, ...$endpoint->handlers],
            );
        }

        return $endpoints;
    }

    /**
     * @return array{list<\Socket>, list<\Socket>} Sockets to wait for reading and for writing.
     */
    private function sockets(): array
    {
        $read = $write = [];
        foreach ($this->servers as $server) {
            [$serverRead, $serverWrite] = $server->getSockets();
            $read = [...$read, ...$serverRead];
            $write = [...$write, ...$serverWrite];
        }

        return [$read, $write];
    }

    /**
     * @param Sender[] $senders
     */
    private function sendBuffer(array $senders = []): void
    {
        $data = $this->buffer->getAndClean();

        // Out of a fiber, as nothing in mapping suspends: the events are stored before the senders get the frames
        $this->collector?->collect($data);

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

    /**
     * @param string $value {@see App::$httpMaxInflatedSize}
     *
     * @return int<1, max>
     */
    private function maxInflatedBodySize(string $value): int
    {
        try {
            $bytes = MemoryLimit::parse($value);
        } catch (\InvalidArgumentException) {
            $bytes = null;
        }

        // `-1` parses to null, as no memory limit: here it is no size either
        if ($bytes !== null) {
            return $bytes;
        }

        $this->logger->warning(
            'TRAP_HTTP_MAX_INFLATED_SIZE `%s` is not a size, as `64M`: the default %d bytes are used.',
            $value,
            Traffic\Parser\Http::MAX_INFLATED_BODY_SIZE,
        );

        return Traffic\Parser\Http::MAX_INFLATED_BODY_SIZE;
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
