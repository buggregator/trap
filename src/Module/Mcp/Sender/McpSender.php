<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Mcp\Sender;

use Buggregator\Trap\Module\Mcp\Config\Mcp as Config;
use Buggregator\Trap\Module\Mcp\Http\Endpoint;
use Buggregator\Trap\Module\Mcp\McpStatus;
use Buggregator\Trap\Module\Mcp\View\TextCache;
use Buggregator\Trap\Server\Event\EventStore;
use Buggregator\Trap\Server\Processable;
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
final class McpSender implements Sender, ConsumesEvents, ServesHttp, Processable
{
    /** Seconds to render the texts for at a time. */
    private const WARM_SLICE = 0.01;

    /** Nanoseconds between the slices at least. */
    private const WARM_PAUSE = 50_000_000;

    /** Times the slice took that the pause after it lasts at least: the rendering takes a fifth of the loop. */
    private const WARM_PAUSE_RATIO = 4;

    /** {@see \hrtime()} to render the texts at. */
    private int $nextWarm;

    /**
     * Created only when the MCP server is chosen: `get_event` prints the frames of an event, so the store
     * keeps them from then on, and the modules keep their data for the tools.
     */
    public function __construct(
        private readonly Endpoint $endpoint,
        private readonly Config $config,
        EventStore $events,
        McpStatus $status,
        private readonly TextCache $texts,
    ) {
        $events->keepFrames();
        $status->turnOn();
        $this->nextWarm = (int) \hrtime(true);
    }

    #[\Override]
    public function send(iterable $frames): void {}

    /**
     * Renders the texts of the new events ahead of a search, a slice at a time. A text may take longer than the
     * slice: then the pause after it grows, see {@see self::WARM_PAUSE_RATIO}.
     */
    #[\Override]
    public function process(): void
    {
        $start = (int) \hrtime(true);
        if ($start < $this->nextWarm) {
            return;
        }

        $this->texts->warm(self::WARM_SLICE);
        $end = (int) \hrtime(true);
        $this->nextWarm = $end + \max(self::WARM_PAUSE, ($end - $start) * self::WARM_PAUSE_RATIO);
    }

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
