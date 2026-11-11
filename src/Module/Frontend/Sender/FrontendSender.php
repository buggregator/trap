<?php

declare(strict_types=1);

namespace Buggregator\Trap\Module\Frontend\Sender;

use Buggregator\Trap\Module\Frontend;
use Buggregator\Trap\Module\Frontend\Config\Frontend as Config;
use Buggregator\Trap\Server\Processable;
use Buggregator\Trap\Server\Proto\Frame;
use Buggregator\Trap\Server\Sender;
use Buggregator\Trap\Server\Sender\HttpEndpoint;
use Buggregator\Trap\Server\Sender\ServesHttp;

/**
 * @internal
 */
final class FrontendSender implements Sender, Processable, ServesHttp
{
    /**
     * Created only when the UI is chosen, so being created is what turns the UI on for the modules.
     */
    public function __construct(
        private readonly Frontend\ConnectionPool $connectionPool,
        private readonly Frontend\FrameHandler $handler,
        private readonly Frontend\Http\Pipeline $pipeline,
        private readonly Frontend\Http\RequestHandler $requestHandler,
        private readonly Config $config,
        Frontend\UiChannel $uiChannel,
    ) {
        $uiChannel->enable();
    }

    /**
     * @param iterable<Frame> $frames
     */
    #[\Override]
    public function send(iterable $frames): void
    {
        foreach ($frames as $frame) {
            \assert($frame instanceof Frame);
            $this->handler->handle($frame);
        }
    }

    #[\Override]
    public function process(): void
    {
        $this->connectionPool->process();
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
