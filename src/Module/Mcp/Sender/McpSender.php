<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp\Sender;

use Buggregator\Trap\Module\Mcp\Config\Mcp as Config;
use Buggregator\Trap\Module\Mcp\Http\Endpoint;
use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Server\Sender;
use Buggregator\Trap\Server\Sender\ConsumesEvents;
use Buggregator\Trap\Server\Sender\HttpEndpoint;
use Buggregator\Trap\Server\Sender\ServesHttp;

/**
 * Serves the MCP server at `/mcp` of its HTTP endpoint. The tools read the event buffer the web UI shows:
 * as an event consumer, this sender has it filled without the UI too.
 *
 * @internal
 */
final class McpSender implements Sender, ConsumesEvents, ServesHttp
{
    /**
     * Created only when the MCP server is chosen: `get_event` prints the frames of an event, so the store
     * keeps them from then on.
     */
    public function __construct(
        private readonly Endpoint $endpoint,
        private readonly Config $config,
        EventStore $events,
    ) {
        $events->keepFrames();
    }

    #[\Override]
    public function send(iterable $frames): void {}

    #[\Override]
    public function httpEndpoint(): HttpEndpoint
    {
        return new HttpEndpoint(
            port: $this->config->port,
            host: $this->config->host,
            middlewares: [$this->endpoint],
            handlers: [],
        );
    }
}
