<?php

declare(strict_types=1);

namespace Buggregator\Client\Sender;

final class SaasSender extends SocketSender
{
    private string $uuid;

    public function __construct(
        string $uuid = null,
        string $host = '127.0.0.1',
        int $port = 9912,
        private readonly string $clientVersion = '0.1',
    ) {
        $this->uuid = $uuid ?? $this->createUuid();

        parent::__construct($host, $port);
    }

    protected function makePackage(string $payload): string
    {
        return "1|$this->clientVersion|$this->uuid|$payload\n";
    }

    /**
     * Generate UUID v4
     */
    private function createUuid(): string
    {
        $data = \random_bytes(16);

        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10

        return \vsprintf('%s%s-%s-%s-%s-%s%s%s', \str_split(\bin2hex($data), 4));
    }
}
