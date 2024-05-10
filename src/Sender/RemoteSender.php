<?php

declare(strict_types=1);

namespace Buggregator\Trap\Sender;

use Buggregator\Trap\Info;
use Buggregator\Trap\Support\Uuid;

/**
 * @internal
 */
final class RemoteSender extends SocketSender
{
    private string $uuid;

    private readonly string $clientVersion;

    public function __construct(
        string $uuid = null,
        string $host = '127.0.0.1',
        int $port = 9912,
    ) {
        $this->clientVersion = Info::version();
        $this->uuid = $uuid ?? Uuid::generate();

        parent::__construct($host, $port);
    }

    protected function makePackage(string $payload): string
    {
        return "1|$this->clientVersion|$this->uuid|$payload\n";
    }
}
