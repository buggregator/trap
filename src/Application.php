<?php

declare(strict_types=1);

namespace Buggregator\Trap;

use Buggregator\Trap\Config\Server\App;
use Buggregator\Trap\Config\Server\Files\SPX as SPXFileConfig;
use Buggregator\Trap\Config\Server\Files\XDebug as XDebugFileConfig;
use Buggregator\Trap\Config\Server\Files\XHProf as XHProfFileConfig;
use Buggregator\Trap\Config\Server\Frontend as FrontendConfig;
use Buggregator\Trap\Config\Server\SocketServer;
use Buggregator\Trap\Config\Server\TcpPorts;
use Buggregator\Trap\Handler\Http\Handler\Websocket;
use Buggregator\Trap\Handler\Http\Middleware;
use Buggregator\Trap\Proto\Buffer;
use Buggregator\Trap\Service\Container;
use Buggregator\Trap\Socket\Client;
use Buggregator\Trap\Socket\Server;
use Buggregator\Trap\Socket\SocketStream;
use Buggregator\Trap\Support\Timer;
use Buggregator\Trap\Traffic\Inspector;

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
            \array_map(static fn(SocketServer $item): ?int => $item->type === 'tcp' ? $item->port : null, $map, ),
            true,
        );
        $withFrontend and $this->configureFrontend($feSeparated);

        $inspectorWithFrontend = $inspector = $container->make(Inspector::class, [
            // new Traffic\Dispatcher\WebSocket(),
            new Traffic\Dispatcher\VarDumper(),
            new Traffic\Dispatcher\Http(
                [
                    $this->container->get(Middleware\Resources::class),
                    $this->container->get(Middleware\DebugPage::class),
                    $this->container->get(Middleware\RayRequestDump::class),
                    $this->container->get(Middleware\SentryTrap::class),
                    $this->container->get(Middleware\XHProfTrap::class),
                ],
                [new Websocket()],
            ),
            new Traffic\Dispatcher\Smtp(),
            new Traffic\Dispatcher\Monolog(),
        ]);
        $this->processors[] = $inspector;


        if ($withFrontend && !$feSeparated) {
            $inspectorWithFrontend = $container->make(Inspector::class, [
                new Traffic\Dispatcher\VarDumper(),
                new Traffic\Dispatcher\Http(
                    [
                        $this->container->get(Sender\Frontend\Http\Pipeline::class),
                        $this->container->get(Middleware\Resources::class),
                        $this->container->get(Middleware\DebugPage::class),
                        $this->container->get(Middleware\RayRequestDump::class),
                        $this->container->get(Middleware\SentryTrap::class),
                        $this->container->get(Middleware\XHProfTrap::class),
                    ],
                    [$this->container->get(Sender\Frontend\Http\RequestHandler::class)],
                ),
                new Traffic\Dispatcher\Smtp(),
                new Traffic\Dispatcher\Monolog(),
            ]);
            $this->processors[] = $inspectorWithFrontend;
        }

        $this->configureFileObserver();

        foreach ($map as $config) {
            $withFrontend && !$feSeparated && $config->type === 'tcp' && $config->port === $feConfig->port
                ? $this->prepareServerFiber($config, $inspectorWithFrontend, $this->logger)
                : $this->prepareServerFiber($config, $inspector, $this->logger);
        }
    }

    /**
     * @param positive-int $sleep Sleep time in microseconds
     */
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

    /**
     * @param SocketServer $config
     * @param Inspector $inspector
     * @return \Fiber
     */
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
        $this->processors[] = $this->senders[] = $wsSender = Sender\FrontendSender::create($this->logger);
        $this->container->set($wsSender);
        $this->container->set($wsSender->getEventStorage());
        $this->container->set($wsSender->getConnectionPool());

        if (!$separated) {
            return;
        }

        // Separated port
        $inspector = $this->container->make(Inspector::class, [
            new Traffic\Dispatcher\Http(
                [$this->container->get(Sender\Frontend\Http\Pipeline::class)],
                [$this->container->get(Sender\Frontend\Http\RequestHandler::class)],
                silentMode: true,
            ),
        ]);
        $this->processors[] = $inspector;
        /** @var TcpPorts $tcpConfig */
        $tcpConfig = $this->container->get(TcpPorts::class);
        $config = $this->container->get(FrontendConfig::class);
        $this->prepareServerFiber(
            new SocketServer(port: $config->port, pollingInterval: $tcpConfig->pollingInterval),
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
            $config->port,
            payloadSize: 524_288,
            acceptPeriod: \max(50, $config->pollingInterval) / 1e6,
            clientInflector: $clientInflector,
            logger: $this->logger,
        );
    }

    private function configureFileObserver(): void
    {
        $this->processors[] = $this->container->make(Service\FilesObserver::class, [
            $this->container->get(XHProfFileConfig::class),
            $this->container->get(XDebugFileConfig::class),
            $this->container->get(SPXFileConfig::class),
        ]);
    }
}
