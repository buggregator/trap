<?php

declare(strict_types=1);

namespace Buggregator\Trap;

use Buggregator\Trap\Config\Server\Frontend as FrontendConfig;
use Buggregator\Trap\Config\Server\SocketServer;
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
    /**
     * @var Processable[]
     */
    private array $processors = [];

    /**
     * @var Server[]
     */
    private array $servers = [];

    /**
     * @var \Fiber[] Any tasks in fibers
     */
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

        $inspector = $container->make(Inspector::class, [
            // new Traffic\Dispatcher\WebSocket(),
            new Traffic\Dispatcher\VarDumper(),
            new Traffic\Dispatcher\Http(
                [
                    new Middleware\Resources(),
                    new Middleware\DebugPage(),
                    new Middleware\RayRequestDump(),
                    new Middleware\SentryTrap(),
                ],
                [new Websocket()],
            ),
            new Traffic\Dispatcher\Smtp(),
            new Traffic\Dispatcher\Monolog(),
        ]);
        $this->processors[] = $inspector;

        $withFrontend and $this->configureFrontend(8000);

        foreach ($map as $config) {
            $this->prepareServerFiber($config, $inspector, $this->logger);
        }
    }

    /**
     * @param positive-int $sleep Sleep time in microseconds
     */
    public function run(int $sleep = 50): void
    {
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
            function () {
                foreach ($this->servers as $server) {
                    $server->cancel();
                }
            }
        );
    }

    public function prepareServerFiber(SocketServer $config, Inspector $inspector, Logger $logger): \Fiber
    {
        return $this->fibers[] = new \Fiber(function () use ($config, $inspector, $logger) {
            do {
                try {
                    $this->processors[] = $this->servers[$config->port] = $this->createServer($config, $inspector);

                    return;
                } catch (\Throwable) {
                    $logger->error("Can't create TCP socket on port {$config->port}.");
                    (new Timer(1.0))->wait();
                }
            } while (!$this->cancelled);
        });
    }

    /**
     * @param int<1, 65535> $port
     */
    public function configureFrontend(int $port): void
    {
        $this->senders[] = $wsSender = Sender\FrontendSender::create($this->logger);

        $inspector = $this->container->make(Inspector::class, [
            new Traffic\Dispatcher\Http(
                [
                    new Sender\Frontend\Http\Cors(),
                    new Sender\Frontend\Http\StaticFiles(),
                    new Sender\Frontend\Http\EventAssets($this->logger, $wsSender->getEventStorage()),
                    new Sender\Frontend\Http\Router($this->logger, $wsSender->getEventStorage()),
                ],
                [new Sender\Frontend\Http\RequestHandler($wsSender->getConnectionPool())],
                silentMode: true,
            ),
        ]);
        $this->processors[] = $inspector;
        $this->processors[] = $wsSender;
        $config = $this->container->get(FrontendConfig::class);
        $this->prepareServerFiber(new SocketServer(port: $config->port), $inspector, $this->logger);
    }

    /**
     * @param Sender[] $senders
     */
    private function sendBuffer(array $senders = []): void
    {
        $data = $this->buffer->getAndClean();

        foreach ($senders as $sender) {
            $this->fibers[] = new \Fiber(
                static fn () => $sender->send($data)
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
            clientInflector: $clientInflector,
            logger: $this->logger,
        );
    }
}
