<?php

declare(strict_types=1);

namespace Buggregator\Client;

use Buggregator\Client\Sender\SenderRegistry;

final class SocketServer
{
    /**
     * @param SenderRegistry $senders
     * @param int<1, 65535> $defaultPort
     * @param positive-int $defaultSleep
     */
    public function __construct(
        private readonly SenderRegistry $senders,
        private readonly int $defaultPort = 9912,
        private readonly int $defaultSleep = 50,
    ) {
    }

    /**
     * @param non-empty-string[] $senders
     */
    public function run(array $senders = ['console'], ?int $port = null): void
    {
        $port = $port ?: $this->defaultPort;

        $bootstrap = new Bootstrap(
            new \stdClass(),
            [
                $port => [],
            ],
            $this->senders->getSenders($senders)
        );

        while (true) {
            $bootstrap->process();
            \usleep($this->defaultSleep);
        }
    }
}
