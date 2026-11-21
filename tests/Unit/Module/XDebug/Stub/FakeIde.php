<?php

declare(strict_types=1);

namespace Buggregator\Trap\Tests\Unit\Module\XDebug\Stub;

use Buggregator\Trap\Module\XDebug\Dbgp\PacketReader;
use Buggregator\Trap\Module\XDebug\Link\Link;

/**
 * An IDE for the tests: keeps the packets it got and sends the commands queued with {@see command()}.
 */
final class FakeIde implements Link
{
    /** @var list<string> */
    public array $packets = [];

    public bool $closed = false;
    private string $out = '';
    private readonly PacketReader $reader;

    public function __construct()
    {
        $this->reader = new PacketReader();
    }

    public function command(string $line): void
    {
        $this->out .= $line . "\0";
    }

    #[\Override]
    public function send(string $bytes): bool
    {
        $this->packets = [...$this->packets, ...$this->reader->push($bytes)];

        return !$this->closed;
    }

    #[\Override]
    public function receive(): ?string
    {
        if ($this->closed) {
            return null;
        }
        [$data, $this->out] = [$this->out, ''];

        return $data;
    }

    #[\Override]
    public function close(): void
    {
        $this->closed = true;
    }

    #[\Override]
    public function isLoopback(): bool
    {
        return false;
    }
}
