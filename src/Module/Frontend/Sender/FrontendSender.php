<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Sender;

use Buggregator\Trap\Module\Frontend;
use Buggregator\Trap\Module\Frontend\Config\Frontend as Config;
use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Server\Logger;
use Buggregator\Trap\Server\Processable;
use Buggregator\Trap\Server\Sender;
use Buggregator\Trap\Server\Sender\ConsumesEvents;
use Buggregator\Trap\Server\Sender\HttpEndpoint;
use Buggregator\Trap\Server\Sender\ObservesHttpHost;
use Buggregator\Trap\Server\Telemetry\TelemetryStore;

/**
 * @internal
 */
final class FrontendSender implements Sender, ConsumesEvents, ObservesHttpHost, Processable
{
    /**
     * Created only when the UI is chosen, so being created is what turns the UI on for the modules.
     */
    public function __construct(
        private readonly Frontend\ConnectionPool $connectionPool,
        private readonly Frontend\Http\Pipeline $pipeline,
        private readonly Frontend\Http\RequestHandler $requestHandler,
        private readonly Config $config,
        Frontend\UiChannel $uiChannel,
        EventStore $events,
        Frontend\EventPusher $pusher,
        private readonly Logger $logger,
        TelemetryStore $telemetry = new TelemetryStore(),
        ?Frontend\TelemetryPusher $telemetryPusher = null,
    ) {
        $uiChannel->enable();
        $events->listen($pusher);
        $telemetry->listen($telemetryPusher ?? new Frontend\TelemetryPusher(new Frontend\Publisher($connectionPool)));
    }

    /**
     * The UI gets events, not frames: the {@see Frontend\EventPusher} pushes them as the store gets them.
     */
    #[\Override]
    public function send(iterable $frames): void {}

    #[\Override]
    public function process(): void
    {
        $this->connectionPool->process();
    }

    /**
     * Warns when anyone who reaches the port can read the events: the UI asks for no token,
     * the one of the MCP server covers `/mcp` only.
     */
    #[\Override]
    public function listensOn(string $host): void
    {
        HttpEndpoint::isLoopback($host) or $this->logger->warning(
            'Web UI: %s listens beyond the loopback: anyone who reaches the port can read the caught events. '
            . 'The UI asks for no token.',
            HttpEndpoint::url($host, $this->config->port),
        );
    }

    #[\Override]
    public function httpEndpoint(): HttpEndpoint
    {
        return new HttpEndpoint(
            port: $this->config->port,
            host: $this->config->host,
            middlewares: [$this->pipeline],
            handlers: [$this->requestHandler],
        );
    }
}
